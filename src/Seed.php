<?php

namespace Drupal\quant;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\Core\Entity\EntityInterface;

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
    $vid = $node->get('vid')->value;
    $message = "Processing {$node->title->value} (Revision: {$vid})";

    // Export via event dispatcher.
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($node));

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
    drupal_set_message($message);
  }

  /**
   * Find lunr assets.
   * This includes static output from the lunr module.
   */
  public static function findLunrAssets() {
    $filesPath = \Drupal::service('file_system')->realpath(file_default_scheme() . "://lunr_search");

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
    // @todo: Add event dispatch.
  }

  /**
   * Trigger an internal http request to retrieve node markup.
   * Seeds an individual node update to Quant.
   */
  public static function seedNode($entity) {

    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;
    $url = $entity->toUrl()->toString();

    // Special case for home-page, rewrite alias to /.
    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');

    if ((strpos($front, '/node/') === 0) && $entity->get('nid')->value == substr($front, 6)) {
      $url = "/";
    }

    // Generate a request token.
    $token = \Drupal::service('quant.token_manager')->create($nid);

    $markup = self::markupFromRoute($url, [
      'quant_revision' => $rid,
      'quant_token' => $token,
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

    // This should get the entity alias.
    $url = $entity->toUrl()->toString();

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

    // Always create canonical redirects.
    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent("/node/{$nid}", $url, 301));

  }

  /**
   * Delete the path from Quant.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public static function deleteNode(EntityInterface $entity) {
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
  protected static function markupFromRoute($route, array $query = []) {

    // Cleanse route.
    $route = str_replace('//', '/', $route);

    // Build internal request.
    $config = \Drupal::config('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host . $route;

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] : [];

    // @todo; Note: Passing in the Host header fixes issues with absolute links.
    // It may also cause some redirects to the real host.
    // Best to trap redirects and re-run against the final path.
    $response = \Drupal::httpClient()->get($url, [
      'http_errors' => FALSE,
      'query' => $query,
      'headers' => [
        'Host' => $hostname,
      ],
      'auth' => $auth,
      'allow_redirects' => FALSE,
    ]);

    $markup = '';

    if ($response->getStatusCode() == 301 || $response->getStatusCode() == 302) {
      $destination = reset($response->getHeader('Location'));

      // Strip quant params from destination.
      $destination = self::removeQuantParams($destination);

      // Ensure relative for internal redirect.
      $destination = self::rewriteRelative($destination);

      \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($route, $destination, $response->getStatusCode()));
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      $markup = self::removeQuantParams($response->getBody());
    }
    else {
      $messenger = \Drupal::messenger();
      $messenger->addMessage("Non-200 response for {$route}: " . $response->getStatusCode(), $messenger::TYPE_WARNING);
    }

    return $markup;

  }

  /**
   * Returns markup with quant params removed.
   *
   * @param string $markup
   *   The markup to search and remove query params from.
   *
   * @return string
   *   Sanitised markup string.
   */
  private static function removeQuantParams($markup) {

    // Ensure &amp; is replaced with &
    $markup = preg_replace('/&amp;/i', '&', $markup);

    // Replace ?quant_revision=XX&quant_token=XX&additional_params with ?
    $markup = preg_replace('/\?quant_revision=(.*&)quant_token=(.*&)/i', '?', $markup);
    // Remove ?quant_revision=XX&quant_token=XX
    $markup = preg_replace("/\?quant_revision=(.*&)quant_token=[^\"']*/i", '', $markup);
    // Remove &quant_revision=XX&quant_token=XX with optional params
    $markup = preg_replace("/\&quant_revision=(.*&)quant_token=[^\"'&]*/i", '', $markup);

    // Replace ?quant_revision=XX&additional_params with ?
    $markup = preg_replace('/\?quant_revision=(.*&)/i', '?', $markup);
    // Remove ?quant_revision=XX
    $markup = preg_replace("/\?quant_revision=[^\"']*/i", '', $markup);
    // Remove &quant_revision=XX with optional params
    $markup = preg_replace("/\&quant_revision=[^\"'&]*/i", '', $markup);

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
