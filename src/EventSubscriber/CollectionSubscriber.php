<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\quant\Event\CollectEntitiesEvent;
use Drupal\quant\Event\CollectFilesEvent;
use Drupal\quant\Event\CollectRedirectsEvent;
use Drupal\quant\Event\CollectRoutesEvent;
use Drupal\quant\Event\CollectTaxonomyTermsEvent;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\quant\Utility;
use Drupal\user\Entity\User;
use Drupal\views\Views;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\node\Entity\Node;
use Drupal\quant\Seed;
use Drupal\redirect\Entity\Redirect;

/**
 * Event subscribers for the quant collection events.
 */
class CollectionSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
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
    $events[QuantCollectionEvents::TAXONOMY_TERMS][] = ['collectTaxonomyTerms'];
    $events[QuantCollectionEvents::FILES][] = ['collectFiles'];
    $events[QuantCollectionEvents::REDIRECTS][] = ['collectRedirects'];
    $events[QuantCollectionEvents::ROUTES][] = ['collectRoutes'];

    return $events;
  }

  /**
   * Collect standard entities.
   *
   * @todo This should support other entity types.
   */
  public function collectEntities(CollectEntitiesEvent $event) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery();

    $bundles = $event->getFormState()->getValue('entity_node_bundles');

    if (!empty($bundles)) {
      $bundles = array_filter($bundles);
      if (!empty($bundles)) {
        $query->condition('type', array_keys($bundles), 'IN');
      }
    }

    $entities = $query->accessCheck(TRUE)->execute();
    $includeLatest = $event->includeLatest();
    $includeRevisions = $event->includeRevisions();

    // Add the latest node to the batch.
    foreach ($entities as $vid => $nid) {
      $filter = [];

      if (!empty($event->getFormState()->getValue('entity_node_languages'))) {
        $filter = array_filter($event->getFormState()->getValue('entity_node_languages'));
      }

      if ($includeRevisions) {
        $entity = Node::load($nid);
        $vids = \Drupal::entityTypeManager()->getStorage('node')->revisionIds($entity);
        $vids = array_diff($vids, [$vid]);
        foreach ($vids as $revision_id) {
          $event->queueItem([
            'id' => $nid,
            'vid' => $revision_id,
            'lang_filter' => $filter,
          ]);
        }
        $entity = NULL;
      }

      // Include latest revisions.
      if ($includeLatest) {
        $event->queueItem([
          'id' => $nid,
          'vid' => $vid,
          'lang_filter' => $filter,
        ]);
      }
    }
  }

  /**
   * Collect taxonomy terms.
   */
  public function collectTaxonomyTerms(CollectTaxonomyTermsEvent $event) {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $terms = $query->accessCheck(TRUE)->execute();

    foreach ($terms as $tid) {
      $event->queueItem([
        'tid' => $tid,
      ]);
    }
  }

  /**
   * Identify redirects.
   */
  public function collectRedirects(CollectRedirectsEvent $event) {
    $query = $this->entityTypeManager->getStorage('redirect')->getQuery();
    $ids = $query->accessCheck(TRUE)->execute();

    foreach ($ids as $id) {
      $redirect = Redirect::load($id);
      $redirects = Seed::getRedirectLocationsFromRedirect($redirect);
      foreach ($redirects as $r) {
        $event->queueItem([
          'source' => $r['source'],
          'destination' => $r['destination'],
          'status_code' => $r['status_code'],
        ]);
      }
    }
  }

  /**
   * Collect files based on provided paths on disk.
   */
  private function collectFilesOnDisk($paths, $event) {
    foreach ($paths as $path) {
      foreach (glob(trim($path)) as $filename) {
        if (is_file($filename)) {
          $path = str_replace(DRUPAL_ROOT, '', $filename);
          $event->queueItem(['file' => $path]);
        }
      }
    }
  }

  /**
   * Collect files based on provided paths on disk.
   */
  private function collectThemeFiles($event) {

    // @todo Support multiple themes (e.g site may have multiple themes changing by route).
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

    $directoryIterator = new \RecursiveDirectoryIterator($themePath, \RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    $regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.woff2|.otf|.ico|.js|.css)$/i', \RecursiveRegexIterator::GET_MATCH);

    foreach ($regex as $name => $r) {
      // Skip node_modules.
      if (preg_match('/node_modules/i', $name)) {
        continue;
      }

      $path = str_replace(DRUPAL_ROOT, '', $name);
      $event->queueItem(['file' => $path]);
    }

    // Include all aggregated css/js files.
    $iterator = new \AppendIterator();

    if (is_dir($filesPath . '/css')) {
      $directoryIteratorCss = new \RecursiveDirectoryIterator($filesPath . '/css', \RecursiveDirectoryIterator::SKIP_DOTS);
      $iterator->append(new \RecursiveIteratorIterator($directoryIteratorCss));
    }

    if (is_dir($filesPath . '/js')) {
      $directoryIteratorJs = new \RecursiveDirectoryIterator($filesPath . '/js', \RecursiveDirectoryIterator::SKIP_DOTS);
      $iterator->append(new \RecursiveIteratorIterator($directoryIteratorJs));
    }

    foreach ($iterator as $fileInfo) {
      $path = str_replace(DRUPAL_ROOT, '', $fileInfo->getPathname());
      $event->queueItem(['file' => $path]);
    }

  }

  /**
   * Collect files for quant seeding.
   */
  public function collectFiles(CollectFilesEvent $event) {

    if ($event->getFormState()->getValue('file_paths')) {
      $paths = [];
      foreach (explode(PHP_EOL, $event->getFormState()->getValue('file_paths_textarea')) as $path) {
        // Paths must be relative to the drupal web root.
        $paths[] = DRUPAL_ROOT . "/" . ltrim($path, '/');
      }
      $this->collectFilesOnDisk($paths, $event);
    }

    if ($event->getFormState()->getValue('theme_assets')) {
      $this->collectThemeFiles($event);
    }

  }

  /**
   * Collect the standard routes.
   */
  public function collectRoutes(CollectRoutesEvent $event) {
    // Handle unpublished content based on settings.
    $disable_drafts = $this->configFactory->get('quant.settings')->get('disable_content_drafts');

    // Add special Quant pages.
    foreach (Utility::getSpecialPages() as $page) {
      $event->queueItem(['route' => $page]);
    }

    // Add custom routes.
    if ($event->getFormState()->getValue('routes')) {
      foreach (explode(PHP_EOL, $event->getFormState()->getValue('routes_textarea')) as $route) {
        if (strpos((trim($route)), '/') !== 0) {
          continue;
        }
        $event->queueItem(['route' => trim($route)]);
      }
    }

    // Add robots.txt file.
    if ($event->getFormState()->getValue('robots')) {
      $event->queueItem(['route' => '/robots.txt']);
    }

    // Add views pages.
    // @todo Allow disabled views pages if disable_content_drafts is false?
    if ($event->getFormState()->getValue('views_pages')) {
      $views_storage = $this->entityTypeManager->getStorage('view');
      $anon = User::getAnonymousUser();

      foreach ($views_storage->loadMultiple() as $view) {
        if ($view->get('id') == 'taxonomy_term') {
          // Taxonomy terms are handled as entities.
          continue;
        }
        $view = Views::getView($view->get('id'));

        $paths = [];

        $display_ids = array_keys($view->storage->get('display'));
        foreach ($display_ids as $display_id) {
          $view->setDisplay($display_id);
          if ($display_id != 'default' && $view->display_handler->isEnabled() && $view->access($display_id, $anon) && $path = $view->getPath()) {

            // Exclude contextual filters for now.
            if (strpos($path, '%') !== FALSE) {
              continue;
            }

            if (in_array($path, $paths)) {
              continue;
            }

            if (strpos($path, 'admin') > -1) {
              // @todo permission checks in the views.
              continue;
            }

            $paths[] = $path;
            $base = \Drupal::request()->getBaseUrl();
            $event->queueItem(['route' => $base . "/{$path}"]);

            // Language negotiation may also provide path prefixes.
            if ($prefixes = \Drupal::config('language.negotiation')->get('url.prefixes')) {
              foreach ($prefixes as $prefix) {
                if ($prefix) {
                  $event->queueItem(['route' => "/{$prefix}/{$path}"]);
                }
              }
            }
          }
        }
      }
    }
  }

}
