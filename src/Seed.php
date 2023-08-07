<?php

namespace Drupal\quant;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\language\Plugin\LanguageNegotiation\LanguageNegotiationUrl;
use Drupal\node\Entity\Node;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use GuzzleHttp\Exception\ConnectException;

/**
 * Seed Manager.
 *
 * The workhorse of Quant, responsible for orchestrating Drupal events and
 * emitting Quant module events so that content can be pushed to the edge.
 *
 * @todo define as a service and use dependency injection.
 */
class Seed {

  /**
   * Batch finish callback for the seed.
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
   *
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

    $files[] = '/' . \Drupal::service('extension.list.module')->getPath('lunr') . '/js/search.worker.js';
    $files[] = '/' . \Drupal::service('extension.list.module')->getPath('lunr') . '/js/vendor/lunr/lunr.min.js';

    return $files;
  }

  /**
   * Find lunr routes.
   *
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
   *
   * @todo Unpublish redirects when content is deleted?
   */
  public static function seedRedirect($redirect) {

    // If the source path has changed, unpublish the old path as the redirect
    // from that path no longer works in Drupal.
    if (!$redirect->isNew()) {
      $originalSource = $redirect->original->getSourcePathWithQuery();
      if ($originalSource && $originalSource != $redirect->getSourcePathWithQuery()) {
        \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $originalSource, [], NULL), QuantEvent::UNPUBLISH);
      }
    }

    $redirects = self::getRedirectLocationsFromRedirect($redirect);
    foreach ($redirects as $r) {
      $event = new QuantRedirectEvent($r['source'], $r['destination'], $r['status_code']);
      \Drupal::service('event_dispatcher')->dispatch($event, QuantRedirectEvent::UPDATE);
    }
  }

  /**
   * Determine if URL path prefix language negotiation is being used.
   */
  protected static function usesLanguagePathPrefixes() {
    // Only works if there is more than one language.
    $langcodes = \Drupal::languageManager()->getLanguages();
    if (count($langcodes) === 1) {
      return FALSE;
    }

    $usesPrefixes = FALSE;
    $languageInterfaceEnabled = \Drupal::config('language.types')->get('negotiation.language_interface.enabled') ?: [];
    if (isset($languageInterfaceEnabled['language-url'])) {
      $languageUrl = \Drupal::config('language.negotiation')->get('url');
      $usesPrefixes = $languageUrl && $languageUrl['source'] === LanguageNegotiationUrl::CONFIG_PATH_PREFIX;
    }
    return $usesPrefixes;
  }

  /**
   * Get all redirects from a redirect entity.
   *
   * For multilingual sites using path prefixes for language negotiation, the
   * language's path prefix needs to be handled and, in some cases, there need
   * to be multiple redirects, one for each language.
   *
   * @return array
   *   The list of redirects.
   */
  public static function getRedirectLocationsFromRedirect($redirect) {
    $redirects = [];

    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();

    // If site does not use prefixes, return single redirect item.
    if (!self::usesLanguagePathPrefixes()) {
      $redirects[] = [
        'source' => $source,
        'destination' => $destination,
        'status_code' => $statusCode,
      ];
      return $redirects;
    }

    // Get language and prefix configuration.
    $langcode = $redirect->language()->getId();
    $siteDefaultLangcode = \Drupal::service('language.default')->get()->getId();
    $pathPrefixes = \Drupal::config('language.negotiation')->get('url.prefixes');

    // Multilingual redirects can be configured for a specific language or
    // for all languages. If the redirect is configured for all languages,
    // create a redirect for each language.
    $langcodes = [$langcode];
    if ($langcode === LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $langcodes = array_keys(\Drupal::languageManager()->getLanguages());
    }

    // Check if a node or term for this path exists.
    $node = NULL;
    $term = NULL;
    $aliasWithoutLangcode = preg_replace('/^\/(' . $siteDefaultLangcode . ')\//', '/', $destination);
    $path = \Drupal::service('path_alias.manager')->getPathByAlias($aliasWithoutLangcode);
    if (preg_match('/node\/(\d+)/', $path, $matches)) {
      $node = Node::load($matches[1]);
    }
    elseif (preg_match('/taxonomy\/term\/(\d+)/', $path, $matches)) {
      $term = Taxonomy::load($matches[1]);
    }

    // Create multilingual redirects.
    foreach ($langcodes as $langcode) {
      // Path prefix might not be the same as the langcode. For the default
      // language, the path prefix might not be set.
      $pathPrefix = $pathPrefixes[$langcode] ? '/' . $pathPrefixes[$langcode] : '';
      $updatedSource = $pathPrefix . $source;

      // For nodes and terms, get alias associated with the langcode, if any.
      if ($node || $term) {
        $path = $node ? '/node/' . $node->id() : '/taxonomy/term/' . $term->id();
        $alias = \Drupal::service('path_alias.manager')->getAliasByPath($path, $langcode);
        if ($alias == $path) {
          // No alias exists. Note there is currently a Drupal core bug #1125428
          // that prevents getting the alias in some cases.
          // @todo Work around the core bug.
          $updatedDestination = $destination;
        }
        else {
          // Add the prefix to the correct alias.
          $updatedDestination = $pathPrefix . $alias;
        }
      }
      // @todo Test use case where page is not a node or term.
      else {
        $updatedDestination = preg_replace('/^\/(' . $siteDefaultLangcode . ')\//', $pathPrefix . '/', $destination);
      }
      $redirects[] = [
        'source' => $updatedSource,
        'destination' => $updatedDestination,
        'status_code' => $statusCode,
      ];
    }
    return $redirects;
  }

  /**
   * Delete existing redirects via API request.
   */
  public static function deleteRedirect($redirect) {
    $redirects = self::getRedirectLocationsFromRedirect($redirect);
    foreach ($redirects as $r) {
      // QuantEvent can be used to unpublish any resource. Note, the source must
      // be given here and not the destination.
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $r['source'], [], NULL), QuantEvent::UNPUBLISH);
    }
  }

  /**
   * Seeds taxonomy term.
   */
  public static function seedTaxonomyTerm($entity, $langcode = NULL) {
    $tid = $entity->get('tid')->value;

    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    $url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid], $options)->toString();
    $response = self::markupFromRoute($url);

    if (empty($response)) {
      return;
    }

    $meta = [];
    [$markup, $content_type] = $response;

    if (!empty($content_type)) {
      $meta['content_type'] = $content_type;
    }

    $metaManager = \Drupal::service('plugin.manager.quant.metadata');
    foreach ($metaManager->getDefinitions() as $pid => $def) {
      $plugin = $metaManager->createInstance($pid);
      if ($plugin->applies($entity)) {
        $meta = array_merge($meta, $plugin->build($entity));
      }
    }

    // Create canonical redirects from taxonomy/term/172 to the aliased route.
    if ("/taxonomy/term/{$tid}" != $url) {
      // Use the default language alias in the event of multi-lang setup.
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $defaultUrl = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid], ['language' => $defaultLanguage])->toString();
      \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent("/taxonomy/term/{$tid}", $defaultUrl, 301), QuantRedirectEvent::UPDATE);
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent($markup, $url, $meta, NULL, $entity, $langcode), QuantEvent::OUTPUT);
  }

  /**
   * Trigger an internal http request to retrieve node markup.
   *
   * Seeds an individual node update to Quant.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A node interface.
   * @param string $langcode
   *   The node language.
   */
  public static function seedNode(EntityInterface $entity, $langcode = NULL) {

    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;

    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    $url = Url::fromRoute('entity.node.canonical', ['node' => $nid], $options)->toString();

    // Special case for home-page, rewrite URL as /.
    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');

    if ((strpos($front, '/node/') === 0) && $nid == substr($front, 6)) {
      if ($entity->isPublished() && $entity->isDefaultRevision()) {
        // Trigger redirect event from alias to home.
        \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent($url, "/", 301), QuantRedirectEvent::UPDATE);
      }
      $url = "/";
    }

    $response = self::markupFromRoute($url, ['quant-revision' => $rid]);
    $meta = [];

    if (empty($response)) {
      return;
    }

    [$markup, $content_type] = $response;

    if (!empty($content_type)) {
      $meta['content_type'] = $content_type;
    }

    $metaManager = \Drupal::service('plugin.manager.quant.metadata');
    foreach ($metaManager->getDefinitions() as $pid => $def) {
      $plugin = $metaManager->createInstance($pid);
      if ($plugin->applies($entity)) {
        $meta = array_merge($meta, $plugin->build($entity, $langcode));
      }
    }

    // Special case pages (403/404/Home)
    $specialPages = [
      '/' => $site_config->get('page.front'),
      '/_quant404' => $site_config->get('page.404'),
      '/_quant403' => $site_config->get('page.403'),
    ];

    foreach ($specialPages as $k => $v) {
      if ((strpos($v, '/node/') === 0) && $entity->get('nid')->value == substr($v, 6)) {
        $url = $k;
      }
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent($markup, $url, $meta, $rid, $entity, $langcode), QuantEvent::OUTPUT);

    // Create canonical redirects from node/123 to the published revision route.
    if ("/node/{$nid}" != $url && $entity->isPublished() && $entity->isDefaultRevision()) {
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $defaultUrl = Url::fromRoute('entity.node.canonical', ['node' => $nid], ['language' => $defaultLanguage])->toString();
      \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent("/node/{$nid}", $defaultUrl, 301), QuantRedirectEvent::UPDATE);
    }
  }

  /**
   * Unpublish the node path from Quant.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The node entity.
   */
  public static function unpublishNode(EntityInterface $entity) {

    $langcode = $entity->language()->getId();
    $nid = $entity->get('nid')->value;

    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    $url = Url::fromRoute('entity.node.canonical', ['node' => $nid], $options)->toString();

    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');
    if ((strpos($front, '/node/') === 0) && $nid == substr($front, 6)) {
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', '/', [], NULL), QuantEvent::UNPUBLISH);
    }

    // Unpublish canonical redirect from node/123.
    if ("/node/{$nid}" != $url) {
      // QuantEvent can be used to unpublish any resource. Note, the source must
      // be given here and not the destination.
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', "/node/{$nid}", [], NULL), QuantEvent::UNPUBLISH);
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $url, [], NULL), QuantEvent::UNPUBLISH);
  }

  /**
   * Unpublish the term path from Quant.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The term entity.
   */
  public static function unpublishTaxonomyTerm(EntityInterface $entity) {

    $langcode = $entity->language()->getId();
    $tid = $entity->get('tid')->value;

    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    $url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid], $options)->toString();

    // Unpublish canonical redirect from taxonomy/term/123.
    if ("/taxonomy/term/{$tid}" != $url) {
      // QuantEvent can be used to unpublish any resource. Note, the source must
      // be given here and not the destination.
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', "/taxonomy/term/{$tid}", [], NULL), QuantEvent::UNPUBLISH);
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $url, [], NULL), QuantEvent::UNPUBLISH);
  }

  /**
   * Attempts a HTTP HEAD request to a given route.
   *
   * @param string $route
   *   The route to poll.
   * @param array $headers
   *   Headers to add to the request.
   *
   * @return bool
   *   Whether a 200 response was received or not.
   */
  public static function headRoute($route, array $headers = []) {

    // Build internal request.
    $config = \Drupal::config('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host . $route;

    $headers['Host'] = $hostname;

    // Generate a signed token and use it in the request.
    $headers['quant-token'] = \Drupal::service('quant.token_manager')->create($route);

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [
      $_SERVER['PHP_AUTH_USER'],
      $_SERVER['PHP_AUTH_PW'],
    ] : [];

    try {
      $response = \Drupal::httpClient()->head($url, [
        'http_errors' => FALSE,
        'headers' => $headers,
        'auth' => $auth,
        'allow_redirects' => FALSE,
        'verify' => boolval($config->get('ssl_cert_verify')),
      ]);
    }
    catch (ConnectException $exception) {
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Returns markup for a given route.
   *
   * @param string $route
   *   The route to collect markup from.
   * @param array $headers
   *   Headers to add to the request.
   *
   * @return string|bool
   *   The markup from the $route.
   */
  public static function markupFromRoute($route, array $headers = []) {

    // Clean double slashes from routes.
    // The exception is oEmbed routes which passes a full URL as query param.
    if (!preg_match('/\/media\/oembed\?url=/', $route)) {
      $route = str_replace('//', '/', $route);
    }

    // Build internal request.
    $config = \Drupal::config('quant.settings');
    $local_host = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $url = $local_host . $route;

    $headers['Host'] = $hostname;

    // Generate a signed token and use it in the request.
    $headers['quant-token'] = \Drupal::service('quant.token_manager')->create($route);

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [
      $_SERVER['PHP_AUTH_USER'],
      $_SERVER['PHP_AUTH_PW'],
    ] : [];

    // @todo ; Note: Passing in the Host header fixes issues with absolute links.
    // It may also cause some redirects to the real host.
    // Best to trap redirects and re-run against the final path.
    try {
      $response = \Drupal::httpClient()->post($url, [
        'http_errors' => FALSE,
        'headers' => $headers,
        'auth' => $auth,
        'allow_redirects' => FALSE,
        'verify' => boolval($config->get('ssl_cert_verify')),
      ]);
    }
    catch (ConnectException $exception) {
      $messenger = \Drupal::messenger();
      $messenger->addMessage("Unable to connect to {$url}", $messenger::TYPE_ERROR);
      \Drupal::logger('quant_seed')->notice($exception->getMessage());
      return FALSE;
    }

    $markup = $content_type = '';

    switch ($response->getStatusCode()) {
      case 301:
      case 302:
        $location_header = $response->getHeader('Location');
        $destination = reset($location_header);
        // Ensure relative for internal redirect.
        $destination = self::rewriteRelative($destination);
        \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent($route, $destination, $response->getStatusCode()), QuantRedirectEvent::UPDATE);
        return FALSE;

      case 200:
        $markup = $response->getBody();
        $content_type = $response->getHeader('content-type');
        break;

      case 404:
        if (strpos($url, '_quant') > -1) {
          $markup = $response->getBody();
          $content_type = $response->getHeader('content-type');
          break;
        }

      default:
        $messenger = \Drupal::messenger();
        $messenger->addMessage("Non-200 response for {$route}: " . $response->getStatusCode(), $messenger::TYPE_WARNING);
        return FALSE;
    }

    return [self::rewriteRelative($markup), $content_type];
  }

  /**
   * Replaces absolute URLs with relative in markup.
   *
   * @param string $markup
   *   The markup to search and rewire relative paths for.
   *
   * @return string
   *   Sanitised markup string.
   */
  public static function rewriteRelative($markup) {
    $config = \Drupal::config('quant.settings');

    // Do not strip host domain unless configured.
    $strip = $config->get('host_domain_strip') ?: FALSE;
    if (!$strip) {
      return $markup;
    }

    // Strip the host domain from everywhere in the content including header
    // metadata such as canonical links.
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $port = $_SERVER['SERVER_PORT'];
    $markup = preg_replace("/(https?:\/\/)?{$hostname}(\:{$port})?/i", '', $markup);

    // Edge case: Replace http://default when run via drush without base_url.
    $markup = preg_replace("/http:\/\/default/i", '', $markup);

    return $markup;
  }

}
