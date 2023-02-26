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

    // Special handling when using URL path prefixes, e.g. foo.com/en/my-page.
    if (self::usesLanguagePathPrefixes()) {
      self::handleMulitingualRedirectEvent($redirect);
    }
    else {
      self::handleRedirectEvent($redirect);
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
   * Handle multilingual redirect event.
   */
  protected static function handleMulitingualRedirectEvent($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();
    $langcode = $redirect->language()->getId();

    // Get language and prefix configuration.
    $siteDefaultLangcode = \Drupal::service('language.default')->get()->getId();
    $pathPrefixes = \Drupal::config('language.negotiation')->get('url.prefixes');

    // Multilingual redirects can be configured for a specific language or
    // for all languages. If the redirect is configured for all languages,
    // create a redirect for each language.
    $langcodes = [$langcode];
    if ($langcode === LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $langcodes = array_keys(\Drupal::languageManager()->getLanguages());
    }

    // Check if a node for this path exists.
    // @todo Handle taxonomy terms.
    $node = NULL;
    $aliasWithoutLangcode = preg_replace('/^\/(' . $siteDefaultLangcode . ')\//', '/', $destination);
    $path = \Drupal::service('path_alias.manager')->getPathByAlias($aliasWithoutLangcode);
    if (preg_match('/node\/(\d+)/', $path, $matches)) {
      $node = Node::load($matches[1]);
    }

    // Create multilingual redirects.
    foreach ($langcodes as $langcode) {
      // Path prefix might not be the same as the langcode. For the default
      // language, the path prefix might not be set.
      $pathPrefix = $pathPrefixes[$langcode] ? '/' . $pathPrefixes[$langcode] : '';
      $updatedSource = $pathPrefix . $source;

      // For nodes, get alias associated with the langcode, if any.
      // @todo Handle taxonomy terms.
      if ($node) {
        $path = '/node/' . $node->id();
        $alias = \Drupal::service('path_alias.manager')->getAliasByPath($path, $langcode);
        if ($alias == $path) {
          // No alias exists.
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

      /*
       * @fixme
       * Example redirects:
       * /node/123 => /en/node123alias.
       * /en/node/123 => /en/node123alias.
       * /node123alias => /en/node123alias.
       * if ($source != $updatedSource) {
       *   \Drupal::service('event_dispatcher')->
       *   dispatch(
       *   new QuantRedirectEvent($source, $updatedDestination, $statusCode),
       *   QuantRedirectEvent::UPDATE);
       * }
       */

      \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent($updatedSource, $updatedDestination, $statusCode), QuantRedirectEvent::UPDATE);
    }
  }

  /**
   * Handle redirect event.
   *
   * @todo Unpublish redirects when content is deleted?
   */
  protected static function handleRedirectEvent($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();
    $isNew = $redirect->isNew();

    if (!(bool) $statusCode && !$isNew) {
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $source, [], NULL), QuantEvent::UNPUBLISH);
      return;
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent($source, $destination, $statusCode), QuantRedirectEvent::UPDATE);
  }

  /**
   * Delete existing redirects via API request.
   */
  public static function deleteRedirect($redirect) {
    $source = $redirect->getSourcePathWithQuery();
    // QuantEvent can be used to unpublish any resource. Note, the source must
    // be given here and not the destination.
    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $source, [], NULL), QuantEvent::UNPUBLISH);
  }

  /**
   * Seeds taxonomy term.
   */
  public static function seedTaxonomyTerm($entity, $langcode = NULL) {

    $url = self::getEntityUrl($entity, $langcode);
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

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent($markup, $url, $meta, NULL, $entity, $langcode), QuantEvent::OUTPUT);

    // Update canonical redirects for taxonomy/term/123.
    self::updateCanonicalRedirects($entity);
  }

  /**
   * Seeds all taxonomy terms.
   */
  public static function seedTaxonomyTerms() {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple();

    foreach ($terms as $term) {
      foreach ($term->getTranslationLanguages() as $langcode => $language) {
        self::seedTaxonomyTerm($term, $langcode);

        \Drupal::logger('quant_seed')->notice("quant_seed sending term: tid: @tid, langcode: @lang",
          [
            '@tid' => $term->get('tid')->value,
            '@lang' => $langcode,
          ]
        );
      }
    }
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
    $url = self::getEntityUrl($entity, $langcode);

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
      if ((strpos($v, '/node/') === 0) && $nid == substr($v, 6)) {
        $url = $k;
      }
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent($markup, $url, $meta, $rid, $entity, $langcode), QuantEvent::OUTPUT);

    // Update canonical redirects for /node/123.
    self::updateCanonicalRedirects($entity);
  }

  /**
   * Unpublish the node path from Quant.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The node entity.
   */
  public static function unpublishNode(EntityInterface $entity) {

    $url = self::getEntityUrl($entity, $entity->language()->getId());

    $site_config = \Drupal::config('system.site');
    $front = $site_config->get('page.front');
    if ((strpos($front, '/node/') === 0) && $entity->get('nid')->value == substr($front, 6)) {
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', '/', [], NULL), QuantEvent::UNPUBLISH);
    }

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $url, [], NULL), QuantEvent::UNPUBLISH);

    // Update canonical redirects for node/123.
    self::updateCanonicalRedirects($entity);
  }

  /**
   * Unpublish the term path from Quant.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The term entity.
   */
  public static function unpublishTaxonomyTerm(EntityInterface $entity) {

    $url = self::getEntityUrl($entity, $entity->language()->getId());

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $url, [], NULL), QuantEvent::UNPUBLISH);

    // Update canonical redirects for taxonomy/term/123.
    self::updateCanonicalRedirects($entity);
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

  /**
   * Get entity URL for the given langcode.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The term entity.
   * @param string $langcode
   *   The node language.
   */
  public static function getEntityUrl(EntityInterface $entity, $langcode = NULL) {
    $id = $entity->id();
    $type = $entity->getEntityTypeId();
    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    return Url::fromRoute('entity.' . $type . '.canonical', [$type => $id], $options)->toString();
  }

  /**
   * Update canonical redirects for the given entity.
   *
   * Canonical redirects are intentionally never unpublished. The content, if
   * unpublished, will cause a 404, which is sufficient. This simplifies the
   * logic for these redirects. This is different than for manually created
   * redirects which can be published and unpublished.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public static function updateCanonicalRedirects(EntityInterface $entity) {
    $redirects = self::getCanonicalRedirects($entity);
    foreach ($redirects as $source => $destination) {
      if (empty($source) || empty($destination)) {
        \Drupal::logger('quant_seed')->warning('Unable to process redirect for entity %type due to empty data.', ['%type' => $entity->getEntityTypeId()]);
        continue;
      }
      if ($source == $destination) {
        \Drupal::logger('quant_seed')->warning('Unable to process redirect for entity %type because source and destination are the same.', ['%type' => $entity->getEntityTypeId()]);
        continue;
      }

      \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent($source, $destination, 301), QuantRedirectEvent::UPDATE);
    }
  }

  /**
   * Get canoncial redirect data for the given entity.
   *
   * Currently only supports nodes and taxonomy terms.
   *
   * @param Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   */
  public static function getCanonicalRedirects(EntityInterface $entity) {
    // @todo Handle other entities besides nodes and terms.
    $id = $entity->id();
    $type = $entity->getEntityTypeId();
    if (!in_array($type, ['node', 'taxonomy_term'])) {
      \Drupal::logger('quant_seed', 'Unsupported entity: %type (%id)',
        ['%type' => $type, '%id' => $id]);
      return [];
    }

    switch ($type) {
      case 'node':
        $source = "/node/{$id}";
        break;

      case 'taxonomy_term':
        $source = "/taxonomy/term/{$id}";
        break;
    }

    // Get path using entity's language which might include the path prefix.
    $entityLangcode = $entity->language()->getId();
    $url = self::getEntityUrl($entity, $entityLangcode);

    // Add /node/123 => /alias or /taxonomy/term/123 => /alias redirect. If the
    // site is multilingual and path prefix is used, the url might have the path
    // prefix in it, e.g. /node/123 => /en/alias.
    $redirects[$source] = $url;

    // Only add more redirects if path prefixes are being used. For simplicity
    // of logic, redirects are added here without checking if the source and the
    // destination are the same, but later we remove any where this is the case.
    if (self::usesLanguagePathPrefixes()) {
      // Get default langcode and path prefixes for all languages.
      $defaultLangcode = \Drupal::languageManager()->getDefaultLanguage()->getId();
      $pathPrefixes = \Drupal::config('language.negotiation')->get('url.prefixes');

      // Add redirect for language URL without path prefix.
      $entityPathPrefix = $pathPrefixes[$entityLangcode] ? '/' . $pathPrefixes[$entityLangcode] : '';
      $urlWithoutPrefix = str_replace($entityPathPrefix . '/', '/', $url);
      $redirects[$urlWithoutPrefix] = $url;

      // Handle multilingual redirects.
      $langcodes = array_keys(\Drupal::languageManager()->getLanguages());
      foreach ($langcodes as $langcode) {
        // Skip entity's langcode to avoid redirect loop.
        if ($langcode == $entity->langcode->value) {
          continue;
        }
        // Path prefix might not be the same as the langcode. For the default
        // language, the path prefix can be empty.
        $pathPrefix = $pathPrefixes[$langcode] ? '/' . $pathPrefixes[$langcode] : '';

        // Each translation can have its own alias. Aliases do not have path
        // prefixes. If no alias is found for the translation, getAliasByPath
        // returns the source path.
        $alias = \Drupal::service('path_alias.manager')->getAliasByPath($source, $langcode);

        // If the path prefix is empty, still add redirect with langcode.
        // E.g. /[langcode]/node/123 => /defaulturl.
        if (empty($pathPrefix)) {
          $redirects['/' . $langcode . $source] = $url;
          $redirects['/' . $langcode . $urlWithoutPrefix] = $url;
        }
        // If this is the default language with a path prefix or no alias has
        // been set for this language, redirect to the default URL.
        // E.g. /[prefix]/node/123 => /defaulturl.
        elseif ($langcode == $defaultLangcode || $source == $alias) {
          $redirects[$pathPrefix . $source] = $url;
        }
        // An alias has been set for this language, so add redirect for it.
        // E.g. /[prefix]/node/123 => /[prefix]/languagealias.
        else {
          $redirects[$pathPrefix . $source] = $pathPrefix . $alias;
        }
      }
    }

    // Remove any redirects where source and destination are the same, since
    // we did not check these above to keep the logic simpler.
    foreach ($redirects as $source => $destination) {
      if ($source == $destination) {
        unset($redirects[$source]);
      }
    }

    \Drupal::logger('kptesting')->notice('redirects for type %type [%id] = <pre>' . print_r($redirects, TRUE) . '</pre>', ['%type' => $type, '%id' => $id]);

    return $redirects;
  }

}
