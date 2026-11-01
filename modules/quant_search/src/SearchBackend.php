<?php

namespace Drupal\quant_search;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\quant_search\Controller\Search;

/**
 * Resolves the hosted search backend the browser talks to.
 *
 * A project runs on Algolia (QuantCDN) or Typesense (QuantGov, platform_mode
 * "aws"). Both resolve to the same keys, so the scripts do not care which one
 * is in use. This is the contract of the Drupal 7 module, the WordPress plugin
 * and the dashboard search pages:
 *   - backend: 'algolia' or 'typesense'.
 *   - app_id: the Algolia application ID ('' on Typesense).
 *   - read_key: the public, search-only key.
 *   - index: the Algolia index or the Typesense collection.
 *   - endpoint: the Typesense URL ('' on Algolia), added at read time.
 *   - sort_by: the Typesense sort_by for the project's custom ranking.
 *   - facets_declared: fields Typesense can facet on ([] means unknown).
 */
class SearchBackend {

  const ALGOLIA = 'algolia';

  const TYPESENSE = 'typesense';

  const DEFAULT_TYPESENSE_ENDPOINT = 'https://search.quantgov.cloud';

  /**
   * Seconds to cache a resolved backend.
   */
  const CACHE_TTL = 60;

  /**
   * Seconds to cache an unavailable backend (API down or search disabled).
   */
  const NEGATIVE_TTL = 30;

  /**
   * The Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface
   */
  protected $client;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Backends resolved in this request, keyed by cache ID.
   *
   * @var array
   */
  protected $resolved = [];

  /**
   * Constructs the service.
   */
  public function __construct(QuantClientInterface $client, ConfigFactoryInterface $config_factory, CacheBackendInterface $cache, LoggerChannelFactoryInterface $logger_factory) {
    $this->client = $client;
    $this->configFactory = $config_factory;
    $this->cache = $cache;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * The backend for the current project, or FALSE when search is unavailable.
   *
   * Two API calls per resolution, so the result is kept for the request and
   * cached briefly. A complete result is cached for CACHE_TTL, an unavailable
   * backend for NEGATIVE_TTL, and an incomplete one (a partial API failure)
   * is not cached. The cache ID carries the API settings, so a project change
   * (or a domain with its own project) resolves afresh.
   *
   * @return array|false
   *   The backend description.
   */
  public function get() {
    $cid = $this->cacheId();
    if (!array_key_exists($cid, $this->resolved)) {
      $cached = $this->cache->get($cid);
      $this->resolved[$cid] = $cached ? $cached->data : $this->resolveAndCache($cid);
    }
    return $this->withEndpoint($this->resolved[$cid]);
  }

  /**
   * Forgets the cached backend, so new facets show on the next render.
   */
  public function clearCache() {
    $this->cache->delete($this->cacheId());
    $this->resolved = [];
  }

  /**
   * Registers a search page's facets with the index.
   *
   * The lang_code and content_type fields are always registered, because
   * page filters use them. The cached backend is cleared, so a new facet
   * shows on the next render instead of after the cache expires.
   *
   * @param array $facets
   *   The page's facets config, as stored on the search page.
   */
  public function registerFacets(array $facets) {
    unset($facets['actions']);
    $keys = Search::processTranslatedFacetKeys($facets);
    if (!$keys) {
      return;
    }
    $this->client->addFacets(array_values(array_unique(array_merge(['lang_code', 'content_type'], array_column($keys, 'facet_key')))));
    $this->clearCache();
  }

  /**
   * Registers the facets of every enabled search page.
   *
   * A page registers its facets when it is saved. A project that moved to
   * another backend starts with an empty index that nobody re-saves the pages
   * against, so a re-index registers them again.
   */
  public function registerAllFacets() {
    $pages = $this->entityTypeManager()->getStorage('quant_search_page')->loadMultiple();
    foreach ($pages as $page) {
      if ($page->get('status')) {
        $this->registerFacets((array) $page->get('facets'));
      }
    }
  }

  /**
   * The Typesense host. Set it in settings.php for stage or local testing.
   *
   * @code
   * $config['quant_search.settings']['typesense_endpoint'] = 'https://search.stage.quantgov.cloud';
   * @endcode
   */
  public function typesenseEndpoint() {
    $endpoint = (string) $this->configFactory->get('quant_search.settings')->get('typesense_endpoint');
    return rtrim($endpoint !== '' ? $endpoint : self::DEFAULT_TYPESENSE_ENDPOINT, '/');
  }

  /**
   * The filter string for a search page in the backend's syntax.
   *
   * @param array $languages
   *   Language codes the page is limited to.
   * @param array $bundles
   *   Content types the page is limited to.
   * @param string|null $manual_filters
   *   The page's manual filter, passed through as written, so it must use the
   *   syntax of the project's backend.
   * @param string $backend
   *   self::ALGOLIA or self::TYPESENSE.
   *
   * @return string
   *   The filter string.
   */
  public static function buildFilters(array $languages, array $bundles, $manual_filters, $backend) {
    $typesense = $backend === self::TYPESENSE;
    $clauses = [];
    foreach (['lang_code' => $languages, 'content_type' => $bundles] as $field => $values) {
      $parts = array_filter(array_map(function ($value) use ($field, $typesense) {
        return self::equals($field, (string) $value, $typesense);
      }, array_values(array_filter($values))));
      if ($parts) {
        $clauses[] = '(' . implode($typesense ? ' || ' : ' OR ', $parts) . ')';
      }
    }
    if (!empty($manual_filters)) {
      $clauses[] = $manual_filters;
    }
    return implode($typesense ? ' && ' : ' AND ', $clauses);
  }

  /**
   * Typesense sort_by for the project's custom ranking ("asc(field)").
   *
   * Text relevance stays first, as on Algolia. Typesense accepts three sort
   * fields, one of which is _text_match.
   *
   * @param array $ranking
   *   The rules from search_config.algolia.custom_ranking.
   *
   * @return string
   *   The sort_by value, or '' when no rule applies.
   */
  public static function sortBy(array $ranking) {
    $clauses = [];
    foreach ($ranking as $rule) {
      if (count($clauses) >= 2) {
        break;
      }
      if (preg_match('/^(asc|desc)\(([A-Za-z_][A-Za-z0-9_]*)\)$/', trim((string) $rule), $m)) {
        $clauses[] = $m[2] . ':' . $m[1];
      }
    }
    return $clauses ? '_text_match:desc,' . implode(',', $clauses) : '';
  }

  /**
   * Removes facets the backend cannot facet on.
   *
   * Typesense answers a query that facets on an undeclared field with a 404,
   * which fails the whole batched InstantSearch request and leaves the page
   * empty. Algolia ignores such a facet, so nothing is removed when the list
   * of declared fields is unknown.
   *
   * @param array $facets
   *   Facets from Search::processTranslatedFacetKeys(), keyed by container.
   * @param array|false $backend
   *   The resolved backend.
   *
   * @return array
   *   The facets that are safe to render, with keys kept.
   */
  public function declaredFacets(array $facets, $backend) {
    if (empty($backend['facets_declared'])) {
      return $facets;
    }
    $declared = array_flip($backend['facets_declared']);
    $kept = array_filter($facets, function ($facet) use ($declared) {
      return isset($declared[$facet['facet_key']]);
    });
    $skipped = array_diff_key($facets, $kept);
    if ($skipped) {
      $this->loggerFactory->get('quant_search')->warning('Skipped facet(s) not registered with the search index: @keys. Save the search page or re-index to register them.', [
        '@keys' => implode(', ', array_unique(array_column($skipped, 'facet_key'))),
      ]);
    }
    return $kept;
  }

  /**
   * Settings for drupalSettings, with the legacy algolia_* keys kept.
   *
   * Themes that override the scripts read algolia_application_id,
   * algolia_read_key and algolia_index. On Typesense algolia_index carries
   * the collection.
   *
   * @param array $backend
   *   The resolved backend.
   *
   * @return array
   *   The settings.
   */
  public static function clientSettings(array $backend) {
    return [
      'backend' => $backend['backend'],
      'app_id' => $backend['app_id'],
      'read_key' => $backend['read_key'],
      'index' => $backend['index'],
      'endpoint' => $backend['endpoint'],
      'sort_by' => $backend['sort_by'],
      'algolia_application_id' => $backend['app_id'],
      'algolia_read_key' => $backend['read_key'],
      'algolia_index' => $backend['index'],
    ];
  }

  /**
   * Libraries that load the vendor client for a backend.
   *
   * @param array $backend
   *   The resolved backend.
   * @param string $widget
   *   The widget: 'page' or 'autocomplete'.
   *
   * @return string[]
   *   Library names.
   */
  public static function libraries(array $backend, $widget) {
    $vendor = $backend['backend'] === self::TYPESENSE ? 'typesense' : 'algolia';
    return ['quant_search/' . $vendor . '-' . $widget];
  }

  /**
   * One equality clause in the backend's syntax.
   *
   * Typesense quotes values with backticks, so a value that contains one is
   * dropped instead of changing the filter's meaning.
   */
  protected static function equals($field, $value, $typesense) {
    if (!$typesense) {
      return $field . ":'" . str_replace("'", '', $value) . "'";
    }
    return strpos($value, '`') === FALSE ? $field . ':=`' . $value . '`' : '';
  }

  /**
   * Resolves the backend and caches the result.
   */
  protected function resolveAndCache($cid) {
    $backend = $this->resolve();
    if ($backend === FALSE) {
      $this->cache->set($cid, FALSE, time() + self::NEGATIVE_TTL);
    }
    elseif ($backend['read_key'] !== '' && $backend['index'] !== '') {
      $this->cache->set($cid, $backend, time() + self::CACHE_TTL);
    }
    return $backend;
  }

  /**
   * Uncached half of get().
   */
  protected function resolve() {
    $project = $this->client->project();
    if (!$project || empty($project->config->search_enabled)) {
      return FALSE;
    }
    $config = $project->config;
    $ranking = isset($config->search_config->algolia->custom_ranking) ? (array) $config->search_config->algolia->custom_ranking : [];
    if (isset($config->platform_mode) && $config->platform_mode === 'aws') {
      return $this->typesense($ranking);
    }
    $index = $config->search_index ?? new \stdClass();
    return [
      'backend' => self::ALGOLIA,
      'app_id' => (string) ($index->algolia_application_id ?? ''),
      'read_key' => (string) ($index->algolia_read_key ?? ''),
      'index' => (string) ($index->algolia_index ?? ''),
      'endpoint' => '',
      'sort_by' => '',
      'facets_declared' => [],
    ];
  }

  /**
   * The read-only key and collection come from the index stats call.
   */
  protected function typesense(array $ranking) {
    $stats = $this->client->search();
    $key = ($stats && isset($stats->readOnlyKey)) ? $stats->readOnlyKey : new \stdClass();
    $collection = (string) ($key->collections[0] ?? '');
    if ($collection === '' && $stats && isset($stats->index->name)) {
      $collection = (string) $stats->index->name;
    }
    // Auto-typed fields can be reported twice.
    $declared = ($stats && isset($stats->settings->attributesForFaceting)) ? array_values(array_unique(array_map('strval', (array) $stats->settings->attributesForFaceting))) : [];
    return [
      'backend' => self::TYPESENSE,
      'app_id' => '',
      'read_key' => (string) ($key->value ?? ''),
      'index' => $collection,
      'endpoint' => '',
      'sort_by' => self::sortBy($ranking),
      'facets_declared' => $declared,
    ];
  }

  /**
   * Adds the Typesense endpoint, a local setting that is never cached.
   */
  protected function withEndpoint($backend) {
    if ($backend && $backend['backend'] === self::TYPESENSE) {
      $backend['endpoint'] = $this->typesenseEndpoint();
    }
    return $backend;
  }

  /**
   * The entity type manager, looked up when needed to keep the service light.
   */
  protected function entityTypeManager() {
    return \Drupal::entityTypeManager();
  }

  /**
   * Cache ID keyed by everything that selects the project.
   */
  protected function cacheId() {
    $config = $this->configFactory->get('quant_api.settings');
    $parts = [
      $config->get('api_endpoint'),
      $config->get('api_account'),
      $config->get('api_project'),
    ];
    return 'quant_search:backend:' . md5(implode('|', $parts));
  }

}
