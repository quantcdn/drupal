<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\node\Entity\Node;
use Drupal\quant\Event\CollectEntitiesEvent;
use Drupal\quant\Event\CollectFilesEvent;
use Drupal\quant\Event\CollectRedirectsEvent;
use Drupal\quant\Event\CollectRoutesEvent;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\quant\Seed;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscribers for the quant collection events.
 */
class CollectioinSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct($entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantCollectionEvents::ENTITY][] = ['collectEntities'];
    $events[QuantCollectionEvents::FILE][] = ['collectFiles'];
    $events[QuantCollectionEvents::REDIRECT][] = ['collectRedirects'];
    $events[QuantCollectionEvents::ROUTE][] = ['collectRoutes'];
  }

  /**
   * Collect standard entities.
   *
   * @TODO: This should support other entity types.
   */
  public function collectEntities(CollectEntitiesEvent $event) {
    // @TODO: Dependency inject this.
    $query = \Drupal::entityQuery('node');
    $nids = $query->execute();

    // Add nodes to export batch.
    foreach ($nids as $key => $value) {
      $node = Node::load($value);

      // Export all node revisions.
      if ($form_state->getValue('entity_node_revisions')) {
        $vids = \Drupal::entityManager()->getStorage('node')->revisionIds($node);

        foreach ($vids as $vid) {
          $nr = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($vid);
          $event->addEntity($nr);
        }
      }
      // Export current node revision.
      $event->addEntity($node);
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
    // @todo: Find path programatically
    // @todo: Support multiple themes (e.g site may have multiple themes changing by route).
    $config = \Drupal::config('system.theme');
    $themeName = $config->get('default');
    $themePath = DRUPAL_ROOT . '/themes/custom/' . $themeName;
    $filesPath = \Drupal::service('file_system')->realpath(file_default_scheme() . "://");

    if (!is_dir($themePath)) {
      echo "Theme dir does not exist";
      die;
    }

    $directoryIterator = new \RecursiveDirectoryIterator($themePath);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    $regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.otf)$/i', \RecursiveRegexIterator::GET_MATCH);

    foreach ($regex as $name => $r) {
      $files[] = str_replace(DRUPAL_ROOT, '', $name);
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
    $views_storage = $this->entityTypeManager->getStorage('view');
    $anon = User::getAnonymousUser();

    foreach ($views_storage->loadMultiple() as $view) {
      $view = \Drupal\views\Views::getView($view->get('id'));
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
