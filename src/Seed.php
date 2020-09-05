<?php

namespace Drupal\quant;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;


/**
 * Seed Manager.
 *
 * @todo define as a service and use dependency injection.
 */
class Seed {

  /**
   * Trigger export node via event dispatcher.
   */
  public static function exportNode($node, &$context) {

    $langcode = $node['langcode'];
    $node = $node['entity'];

    $vid = $node->get('vid')->value;
    $message = "Processing {$node->title->value} (Revision: {$vid})";

    // Export via event dispatcher.
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($node, $langcode));

    $results = [$node->nid->value];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   * Trigger export redirect via event dispatcher.
   */
  public static function exportRedirect($redirect, &$context) {
    $source = $redirect->getSourcePathWithQuery();
    $message = "Processing redirect: {$source}";

    // Export via event dispatcher.
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();
    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($source, $destination, $statusCode));

    $results = [$source];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   * Export arbitrary route (markup).
   */
  public static function exportRoute($route, &$context) {
    $message = "Processing route: {$route}";

    $markup = self::markupFromRoute($route);

    if (empty($markup)) {
      return;
    }

    $config = \Drupal::config('quant.settings');
    $proxy_override = boolval($config->get('proxy_override', false));

    $meta = [
      'info' => [
        'author' => '',
        'date_timestamp' => time(),
        'log' => '',
      ],
      'published' => TRUE,
      'transitions' => [],
      'proxy_override' => $proxy_override,
    ];

    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $route, $meta));

    $context['message'] = $message;
    $context['results'][] = $route;
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

  /**
   *
   */
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
    \Drupal::messenger()->addMessage($message);
  }

  /**
   * Find lunr assets.
   * This includes static output from the lunr module.
   */
  public static function findLunrAssets() {
    $scheme = \Drupal::config('system.file')->get('default_scheme');
    $filesPath = \Drupal::service('file_system')->realpath($scheme . "://lunr_search");

    if (!is_dir($filesPath)) {
      $messenger = \Drupal::messenger();
      $messenger->addMessage('Lunr files not found. Ensure an index has been run.', $messenger::TYPE_WARNING);
      return [];
    }

    $files = [];
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filesPath)) as $filename) {
      if ($filename->isDir()) {
        continue;
      }
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
    $lunr_storage = \Drupal::service('entity_type.manager')->getStorage('lunr_search');
    $routes = [];

    foreach ($lunr_storage->loadMultiple() as $search) {
      $routes[] = $search->getPath();
    }

    return $routes;
  }

  /**
   * Add/update redirect via API request.
   */
  public static function seedRedirect($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();
    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($source, $destination, $statusCode));
  }

  /**
   * Delete existing redirects via API request.
   */
  public static function deleteRedirect($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::UNPUBLISH, new QuantEvent('', $source, [], NULL));
  }

  /**
   * Trigger an internal http request to retrieve node markup.
   * Seeds an individual node update to Quant.
   */
  public static function seedNode($entity, $langcode=NULL) {

    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;

    // Special case for home-page, rewrite alias to /.
    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');

    // Can pass in 'language' here to get language alias.
    // Unable to determine language based on revision id though..?
    $options = ['absolute' => FALSE];

    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    $url = Url::fromRoute('entity.node.canonical', ['node' => $nid], $options)->toString();

    if ((strpos($front, '/node/') === 0) && $entity->get('nid')->value == substr($front, 6)) {
       $url = "/";
    }

    // Generate a request token.
    $token = \Drupal::service('quant.token_manager')->create($nid);

    $markup = self::markupFromRoute($url, [
      'quant-revision' => $rid,
      'quant-token' => $token,
    ]);

    $meta = [];

    if (empty($markup)) {
      return;
    }

    $metaManager = \Drupal::service('plugin.manager.quant.metadata');
    foreach ($metaManager->getDefinitions() as $pid => $def) {
      $plugin = $metaManager->createInstance($pid);
      if ($plugin->applies($entity)) {
        $meta = array_merge($meta, $plugin->build($entity));
      }
    }

    // Special case pages (403/404); 2x exports.
    // One for alias associated with page, one for "special" URLs.
    $site_config = \Drupal::config('system.site');

    $specialPages = [
      '/' => $site_config->get('page.front'),
      '/_quant404' => $site_config->get('page.404'),
      '/_quant403' => $site_config->get('page.403'),
    ];

    foreach ($specialPages as $k => $v) {
      if ((strpos($v, '/node/') === 0) && $entity->get('nid')->value == substr($v, 6)) {
        \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $k, $meta, $rid));
      }
    }

    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $url, $meta, $rid));

    // Create canonical redirects from node/123 to the published revision route.
    $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();

    if ($entity->isPublished() && $entity->isDefaultRevision()) {
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $defaultUrl = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['language' => $defaultLanguage])->toString();
      \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent("/node/{$nid}", $url, 301));
    }
  }

  /**
   * Unpublish the path from Quant.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public static function unpublishRoute(EntityInterface $entity) {
    // @TODO: This should be a quant service.
    $url = $entity->toUrl()->toString();
    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');
    if ((strpos($front, '/node/') === 0) && $entity->get('nid')->value == substr($front, 6)) {
      $url = "/";
    }

    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::UNPUBLISH, new QuantEvent('', $url, [], NULL));
  }

  /**
   * Returns markup for a given route.
   *
   * @param string $route
   *   The route to collect markup from.
   * @param array $query
   *   Query parameters to add to the route.
   *
   * @return string|bool
   *   The markup from the $route.
   */
  protected static function markupFromRoute($route, array $headers = []) {

    // Cleanse route.
    $route = str_replace('//', '/', $route);

    // Build internal request.
    $config = \Drupal::config('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host . $route;

    $headers['Host'] = $hostname;

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] : [];

    // @todo; Note: Passing in the Host header fixes issues with absolute links.
    // It may also cause some redirects to the real host.
    // Best to trap redirects and re-run against the final path.
    $response = \Drupal::httpClient()->post($url, [
      'http_errors' => FALSE,
      'headers' => $headers,
      'auth' => $auth,
      'allow_redirects' => FALSE,
    ]);

    $markup = '';

    if ($response->getStatusCode() == 301 || $response->getStatusCode() == 302) {
      $destination = reset($response->getHeader('Location'));

      // Ensure relative for internal redirect.
      $destination = self::rewriteRelative($destination);

      \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($route, $destination, $response->getStatusCode()));
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      $markup = $response->getBody();
    }
    else {
      $messenger = \Drupal::messenger();
      $messenger->addMessage("Non-200 response for {$route}: " . $response->getStatusCode(), $messenger::TYPE_WARNING);
    }

    return $markup;

  }

  /**
   * Replaces absolute URLs with relative in markup.
   *
   * @param string $markup
   *   The markup to search and rewrie relative paths for.
   *
   * @return string
   *   Sanitised markup string.
   */
  private static function rewriteRelative($markup) {
    $config = \Drupal::config('quant.settings');
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    return preg_replace("/(https?:\/\/)?{$hostname}/i", '', $markup);
  }

}
