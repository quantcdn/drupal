<?php

namespace Drupal\quant;


use Drupal\node\Entity\Node;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\Event\QuantFileEvent;

class Seed {

  /**
   * Trigger export node via event dispatcher.
   */
  public static function exportNode($node, &$context){
    $vid = $node->get('vid')->value;
    $message = "Processing {$node->title->value} (Revision: {$vid})";

    // Export via event dispatcher.
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($node));

    $results = [$node->nid->value];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   * Trigger export file via event dispatcher.
   */
  public static function exportFile($file, &$context) {
    $message = "Processing theme asset: " . basename($file);

    // Export via event dispatcher.
    if (file_exists(DRUPAL_ROOT . $file)) {
      \Drupal::service('event_dispatcher')->dispatch(QuantFileEvent::OUTPUT, new QuantFileEvent(DRUPAL_ROOT . $file, $file));
    }

    $results = [$file];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  public static function finishedSeedCallback($success, $results, $operations) {
    // The 'success' parameter means no fatal PHP errors were detected. All
    // other error management should be handled using 'results'.
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One item processed.', '@count items processed.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    drupal_set_message($message);
  }


  /**
   * Find lunr assets.
   * This includes static output from the lunr module.
   */
  public static function findLunrAssets() {
    $filesPath = \Drupal::service('file_system')->realpath(file_default_scheme() . "://lunr_search");

    $files = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filesPath)) as $filename) {
      if ($filename->isDir()) continue;
      $files[] = str_replace(DRUPAL_ROOT, '', $filename->getPathname());
    }

    $files[] = '/' . drupal_get_path('module', 'lunr') . '/js/search.worker.js';
    $files[] = '/' . drupal_get_path('module', 'lunr') . '/js/vendor/lunr/lunr.min.js';

    return $files;
  }

  /**
   * Find lunr routes.
   * Determine URLs lunr indexes are exposed on.
   */
  public static function findLunrRoutes() {

    // $lunr_storage =  $entity_type_manager->getStorage('lunr_search');

    // foreach ($this->lunrSearchStorage->loadMultiple() as $search) {
    //   $directory = dirname($search->getBaseIndexPath());
    //   foreach (array_keys(file_scan_directory($directory, '/.*/')) as $filename) {
    //     $event->addPath(file_create_url($filename), ['language_processed' => 'language_processed']);
    //   }
    // }

    return $files;
  }

  /**
   * Find theme assets.
   * Currently supports fonts: ttf/woff/otf, images: png/jpeg/svg.
   * @todo: Make this configurable.
   */
  public static function findThemeAssets() {
    // @todo: Find path programatically
    // @todo: Support multiple themes (e.g site may have multiple themes changing by route).
    $config = \Drupal::config('system.theme');
    $themeName = $config->get('default');
    $themePath = DRUPAL_ROOT . '/themes/custom/' . $themeName;
    $filesPath = \Drupal::service('file_system')->realpath(file_default_scheme() . "://");

    if (!is_dir($themePath)) {
      echo "Theme dir does not exist"; die;
    }

    $files = [];

    $directoryIterator = new \RecursiveDirectoryIterator($themePath);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    $regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.otf)$/i', \RecursiveRegexIterator::GET_MATCH);

    foreach($regex as $name => $r) {
      $files[] = str_replace(DRUPAL_ROOT, '', $name);
    }

    // Include all aggregated css/js files.
    $directoryIteratorCss = new \RecursiveDirectoryIterator($filesPath.'/css');
    $directoryIteratorJs  = new \RecursiveDirectoryIterator($filesPath.'/js');

    $iterator = new \AppendIterator();
    $iterator->append(new \RecursiveIteratorIterator( $directoryIteratorCss ));
    $iterator->append(new \RecursiveIteratorIterator( $directoryIteratorJs ));

    foreach ($iterator as $fileInfo) {
      $files[] = str_replace(DRUPAL_ROOT, '', $fileInfo->getPathname());
    }

    return $files;
  }


  /**
   * Trigger an internal http request to retrieve node markup.
   * Seeds an individual node update to Quant.
   */
  public static function seedNode($entity) {

    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;
    $url = $entity->toUrl()->toString();

    // Build internal request.
    $config = \Drupal::config('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host.$url;

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] : [];

    // Special case for home-page, rewrite alias to /.
    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');

    if ((strpos($front, '/node/') === 0) && $entity->get('nid')->value == substr($front, 6)) {
      $url = "{$local_host}/";
    }

    // @todo; Note: Passing in the Host header fixes issues with absolute links.
    // It may also cause some redirects to the real host.
    // Best to trap redirects and re-run against the final path.
    $response = \Drupal::httpClient()->get($url . "?quant_revision=".$rid, [
      'http_errors' => false,
      'query' => ['quant_revision' => $rid ],
      'headers' => [
        'Host' => $hostname,
      ],
      'auth' => $auth
    ]);

    if ($response->getStatusCode() == 200) {
      $markup = $response->getBody();
    }
    else {
      $messenger = \Drupal::messenger();
      $messenger->addMessage('Quant error: ' . $response->getStatusCode(), $messenger::TYPE_WARNING);
      $messenger->addMessage('Quant error: ' . $response->getBody(), $messenger::TYPE_WARNING);
    }

    $meta = [];

    $metaManager = \Drupal::service('plugin.manager.quant.metadata');
    foreach ($metaManager->getDefinitions() as $pid => $def) {
      $plugin = $metaManager->createInstance($pid);
      if ($plugin->applies($entity)) {
        $meta = array_merge($meta, $plugin->build($entity));
      }
    }

    // This should get the entity alias.
    $url = $entity->toUrl()->toString();

    // Special case pages (403/404); 2x exports.
    // One for alias associated with page, one for "special" URLs.
    $specialPages = [
      '/' => $site_config->get('page.front'),
      '/_quant404' => $site_config->get('page.404'),
      '/_quant403' => $site_config->get('page.403'),
    ];

    foreach ($specialPages as $k => $v) {
      if ((strpos($v, '/node/') === 0) && $entity->get('nid')->value == substr($v, 6)) {
        \Drupal::service('event_dispatcher')->dispatch(\Drupal\quant\Event\QuantEvent::OUTPUT, new \Drupal\quant\Event\QuantEvent($markup, $k, $entity, $meta, $rid));
      }
    }

    \Drupal::service('event_dispatcher')->dispatch(\Drupal\quant\Event\QuantEvent::OUTPUT, new \Drupal\quant\Event\QuantEvent($markup, $url, $entity, $meta, $rid));

  }
}
