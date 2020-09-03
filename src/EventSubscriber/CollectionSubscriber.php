<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\node\Entity\Node;
use Drupal\quant\Event\CollectEntitiesEvent;
use Drupal\quant\Event\CollectFilesEvent;
use Drupal\quant\Event\CollectRedirectsEvent;
use Drupal\quant\Event\CollectRoutesEvent;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManager;

/**
 * Event subscribers for the quant collection events.
 */
class CollectionSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManager $entity_type_manager, ConfigFactory $config_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantCollectionEvents::ENTITIES][] = ['collectEntities'];
    $events[QuantCollectionEvents::FILES][] = ['collectFiles'];
    $events[QuantCollectionEvents::REDIRECTS][] = ['collectRedirects'];
    $events[QuantCollectionEvents::ROUTES][] = ['collectRoutes'];

    return $events;
  }

  /**
   * Collect standard entities.
   *
   * @TODO: This should support other entity types.
   */
  public function collectEntities(CollectEntitiesEvent $event) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $disable_drafts = $this->configFactory->get('quant.settings')->get('disable_content_drafts');

    $bundles = array_filter($event->getFormState()->getValue('entity_node_bundles'));

    if (!empty($bundles)) {
      $query->condition('type', array_keys($bundles), 'IN');
    }

    $nids = $query->execute();

    // Add nodes to export batch.
    foreach ($nids as $key => $value) {
      $node = Node::load($value);

      // Iterate translations if enabled.
      $languageFilter = array_filter($event->getFormState()->getValue('entity_node_languages'));

      foreach ($node->getTranslationLanguages() as $langcode => $language) {

        // Skip languages excluded from the filter.
        if (!empty($languageFilter) && !in_array($langcode, $languageFilter)) {
          continue;
        }

        // Retrieve the translated version.
        $node = $node->getTranslation($langcode);

        if ($disable_drafts && !$node->isPublished()) {
          continue;
        }

        if ($event->includeRevisions()) {
          $vids = $this->entityTypeManager->getStorage('node')->revisionIds($node);

          foreach ($vids as $vid) {
            $nr = $this->entityTypeManager->getStorage('node')->loadRevision($vid);

            foreach ($nr->getTranslationLanguages() as $trLangcode => $trLanguage) {
              if (!empty($languageFilter) && !in_array($trLangcode, $languageFilter)) {
                continue;
              }

              $nr = $nr->getTranslation($trLangcode);
              $event->addEntity($nr, $trLangcode);
            }
          }
        }

        // Export current node revision.
        $event->addEntity($node, $langcode);

      }
    }
  }

  /**
   * Identify redirects.
   */
  public function collectRedirects(CollectRedirectsEvent $event) {
    $redirects_storage = $this->entityTypeManager->getStorage('redirect');
    foreach ($redirects_storage->loadMultiple() as $redirect) {
      $event->addEntity($redirect);
    }
  }

  /**
   * Collect files for quant seeding.
   */
  public function collectFiles(CollectFilesEvent $event) {
    if (!$event->getFormState()->getValue('theme_assets')) {
      return;
    }

    // @todo: Find path programmatically.
    // @todo: Support multiple themes (e.g site may have multiple themes changing by route).
    $config = $this->configFactory->get('system.theme');
    $themeName = $config->get('default');
    $path = \Drupal::service('theme_handler')->getTheme($themeName)->getPath();

    $themePath = DRUPAL_ROOT . '/' . $path;
    $scheme = \Drupal::config('system.file')->get('default_scheme');
    $filesPath = \Drupal::service('file_system')->realpath($scheme . "://");

    if (!is_dir($themePath)) {
      echo "Theme dir does not exist";
      die;
    }

    $directoryIterator = new \RecursiveDirectoryIterator($themePath);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    $regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.woff2|.otf)$/i', \RecursiveRegexIterator::GET_MATCH);

    foreach ($regex as $name => $r) {
      $path = str_replace(DRUPAL_ROOT, '', $name);
      $event->addFilePath($path);
    }

    // Include all aggregated css/js files.
    $iterator = new \AppendIterator();

    if (is_dir($filesPath . '/css')) {
      $directoryIteratorCss = new \RecursiveDirectoryIterator($filesPath . '/css');
      $iterator->append(new \RecursiveIteratorIterator($directoryIteratorCss));
    }

    if (is_dir($filesPath . '/js')) {
      $directoryIteratorJs = new \RecursiveDirectoryIterator($filesPath . '/js');
      $iterator->append(new \RecursiveIteratorIterator($directoryIteratorJs));
    }

    foreach ($iterator as $fileInfo) {
      $path = str_replace(DRUPAL_ROOT, '', $fileInfo->getPathname());
      $event->addFilePath($path);
    }
  }

  /**
   * Collect the standard routes.
   */
  public function collectRoutes(CollectRoutesEvent $event) {
    if ($event->getFormState()->getValue('routes_textarea')) {
      foreach (explode(PHP_EOL, $event->getFormState()->getValue('routes_textarea')) as $route) {
        if (strpos((trim($route)), '/') !== 0) {
          continue;
        }
        $event->addRoute(trim($route));
      }
    }

    if ($event->getFormState()->getValue('views_pages')) {
      $views_storage = $this->entityTypeManager->getStorage('view');
      $anon = User::getAnonymousUser();

      foreach ($views_storage->loadMultiple() as $view) {
        $view = Views::getView($view->get('id'));
        $displays = array_keys($view->storage->get('display'));
        foreach ($displays as $display) {
          $view->setDisplay($display);
          if ($view->access($display, $anon) && $path = $view->getPath()) {
            // Exclude contextual filters for now.
            if (strpos($path, '%') !== FALSE) {
              continue;
            }

            $event->addRoute("/{$path}");
          }
        }
      }
    }
  }

}
