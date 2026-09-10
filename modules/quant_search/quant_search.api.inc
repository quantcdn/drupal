<?php

/**
 * @file
 * Quant hosted-search HTTP API client.
 */

/**
 * Builds a fully-qualified Quant API v1 URL.
 */
function quant_search_api_url($path) {
  $endpoint = variable_get('quant_api_endpoint', QUANT_API_ENDPOINT_DEFAULT);
  return rtrim($endpoint, '/') . '/v1' . $path;
}

/**
 * Performs a Quant search API request.
 *
 * @param string $path
 *   Path under /v1, e.g. '/search'.
 * @param string $method
 *   HTTP method.
 * @param array|null $body
 *   Optional payload; JSON-encoded when present.
 *
 * @return object
 *   The drupal_http_request() result. Inspect ->code and ->data.
 */
function quant_search_api_request($path, $method = 'GET', $body = NULL) {
  $headers = quant_api_get_request_headers();
  $options = array('method' => $method, 'headers' => $headers, 'timeout' => 30);
  if ($body !== NULL) {
    $options['headers']['Content-Type'] = 'application/json';
    $options['data'] = drupal_json_encode($body);
  }
  return drupal_http_request(quant_search_api_url($path), $options);
}

/**
 * Returns the Quant project, including search credentials.
 *
 * @return object|false
 *   Decoded project object, or FALSE on failure. Search fields live at
 *   ->config->search_index->{algolia_application_id,algolia_read_key,algolia_index}
 *   and ->config->search_enabled.
 */
function quant_search_api_project() {
  $response = quant_search_api_request('/ping', 'GET');
  if ($response->code == 200 && !empty($response->data)) {
    return json_decode($response->data);
  }
  watchdog('quant_search', 'Project lookup failed: @code', array('@code' => $response->code), WATCHDOG_WARNING);
  return FALSE;
}

/**
 * Resolves the search backend the browser must talk to.
 *
 * A project runs on Algolia (public cloud) or Typesense (QuantGov cloud,
 * platform_mode 'aws'). Both return the same keys so the JS stays
 * backend-agnostic:
 *   - backend:        'algolia' | 'typesense'
 *   - app_id:         Algolia application id ('' on Typesense)
 *   - read_key:       public search key
 *   - index:          Algolia index name or Typesense collection
 *   - endpoint:       Typesense URL ('' on Algolia)
 *   - custom_ranking: the project's sort rules, e.g. ['asc(field)']
 *
 * For local testing against a private Typesense set
 * $conf['quant_search_backend_override'] in settings.php with the same keys.
 *
 * @return array|false
 *   The backend description, or FALSE when search is unavailable.
 */
function quant_search_api_backend() {
  $override = variable_get('quant_search_backend_override', array());
  if (!empty($override['backend'])) {
    return $override + array('app_id' => '', 'read_key' => '', 'index' => '', 'endpoint' => '', 'custom_ranking' => array());
  }
  // Two API round-trips per resolution; memoise per request and cache
  // briefly so a page render does not pay them. The id carries the Quant API
  // settings, so a project change resolves afresh. A complete result is
  // cached for QUANT_SEARCH_BACKEND_CACHE_TTL; an unavailable backend (API
  // down, search disabled) is cached for a shorter window so a failing API
  // costs one round trip per window rather than one per request. An
  // incomplete result (partial API failure) is not cached and retries next
  // request. The endpoint is a local setting merged at read time.
  $static = &drupal_static(__FUNCTION__);
  if (isset($static)) {
    return quant_search_api_backend_with_endpoint($static);
  }
  $cid = quant_search_api_backend_cid();
  $cached = cache_get($cid);
  if ($cached && $cached->expire > REQUEST_TIME) {
    $static = $cached->data;
    return quant_search_api_backend_with_endpoint($static);
  }
  $backend = quant_search_api_backend_resolve();
  if ($backend === FALSE) {
    cache_set($cid, FALSE, 'cache', REQUEST_TIME + QUANT_SEARCH_BACKEND_NEGATIVE_TTL);
  }
  elseif ($backend['read_key'] !== '' && $backend['index'] !== '') {
    cache_set($cid, $backend, 'cache', REQUEST_TIME + QUANT_SEARCH_BACKEND_CACHE_TTL);
  }
  $static = $backend;
  return quant_search_api_backend_with_endpoint($backend);
}

/**
 * Adds the locally configured Typesense endpoint to a resolved backend.
 */
function quant_search_api_backend_with_endpoint($backend) {
  if ($backend && $backend['backend'] === 'typesense') {
    $backend['endpoint'] = variable_get('quant_search_typesense_endpoint', QUANT_SEARCH_TYPESENSE_ENDPOINT_DEFAULT);
  }
  return $backend;
}

/**
 * Cache id for the resolved backend, keyed by everything that selects it.
 */
function quant_search_api_backend_cid() {
  $parts = array(
    variable_get('quant_api_endpoint', ''),
    variable_get('quant_api_customer', ''),
    variable_get('quant_api_project', ''),
  );
  return 'quant_search_backend:' . md5(implode('|', $parts));
}

/**
 * Uncached half of quant_search_api_backend().
 */
function quant_search_api_backend_resolve() {
  $project = quant_search_api_project();
  if (!$project || empty($project->config->search_enabled)) {
    return FALSE;
  }
  $config = $project->config;
  $ranking = isset($config->search_config->algolia->custom_ranking) ? (array) $config->search_config->algolia->custom_ranking : array();
  if (isset($config->platform_mode) && $config->platform_mode === 'aws') {
    return quant_search_api_typesense_backend($ranking);
  }
  $index = isset($config->search_index) ? $config->search_index : new stdClass();
  return array(
    'backend' => 'algolia',
    'app_id' => isset($index->algolia_application_id) ? $index->algolia_application_id : '',
    'read_key' => isset($index->algolia_read_key) ? $index->algolia_read_key : '',
    'index' => isset($index->algolia_index) ? $index->algolia_index : '',
    'endpoint' => '',
    'custom_ranking' => $ranking,
    // Empty means "not known"; Algolia ignores an unconfigured facet rather
    // than failing, so its facets are never filtered.
    'facets_declared' => array(),
  );
}

/**
 * Typesense half of quant_search_api_backend(): the read-only key and the
 * collection come from the index stats call, the endpoint from a setting.
 */
function quant_search_api_typesense_backend(array $ranking) {
  $stats = quant_search_api_stats();
  $key = ($stats && isset($stats->readOnlyKey->value)) ? $stats->readOnlyKey->value : '';
  $collection = ($stats && !empty($stats->readOnlyKey->collections[0])) ? $stats->readOnlyKey->collections[0] : '';
  if ($collection === '' && $stats && isset($stats->index->name)) {
    $collection = $stats->index->name;
  }
  // The collection's facetable fields. Typesense rejects a query that facets
  // on anything else, so the renderer uses this to drop unknown facets
  // (see quant_search_filter_declared_facets). Reported with duplicates when
  // a field is auto-typed, hence array_unique.
  $declared = array();
  if ($stats && isset($stats->settings->attributesForFaceting)) {
    $declared = array_values(array_unique((array) $stats->settings->attributesForFaceting));
  }
  return array(
    'backend' => 'typesense',
    'app_id' => '',
    'read_key' => $key,
    'index' => $collection,
    'endpoint' => '',
    'custom_ranking' => $ranking,
    'facets_declared' => $declared,
  );
}

/**
 * Returns TRUE when hosted search is enabled for the project.
 */
function quant_search_api_enabled() {
  $project = quant_search_api_project();
  return $project && !empty($project->config->search_enabled);
}

/**
 * Sends a batch of search records to the index.
 *
 * @param array $records
 *   A list of record arrays (see quant_search_generate_record()).
 *
 * @return bool
 *   TRUE on HTTP 200.
 */
function quant_search_api_send_records(array $records) {
  if (empty($records)) {
    return TRUE;
  }
  $response = quant_search_api_request('/search', 'POST', array_values($records));
  if ($response->code != 200) {
    watchdog('quant_search', 'Send records failed: @code @data',
      array('@code' => $response->code, '@data' => substr((string) $response->data, 0, 500)),
      WATCHDOG_ERROR);
    return FALSE;
  }
  return TRUE;
}

/**
 * Clears the entire search index.
 */
function quant_search_api_clear() {
  $response = quant_search_api_request('/search/all', 'DELETE');
  return $response->code == 200;
}

/**
 * Removes a single search record (all of its content chunks) by URL.
 *
 * @param string $url
 *   The record URL as produced by quant_search_node_url() / stored in the
 *   record's 'url' attribute. Lower-cased here to match how the backend
 *   stores 'url' (build_search_record_populated() lower-cases it on write).
 *
 * @return bool
 *   TRUE on HTTP 200.
 */
function quant_search_api_delete_record($url) {
  $url = drupal_strtolower(trim((string) $url));
  if ($url === '') {
    return FALSE;
  }
  $headers = quant_api_get_request_headers();
  $headers['Quant-Url'] = $url;
  $options = array('method' => 'DELETE', 'headers' => $headers, 'timeout' => 30);
  $response = drupal_http_request(quant_search_api_url('/search'), $options);
  if ($response->code != 200) {
    watchdog('quant_search', 'Delete record failed for @url: @code @data',
      array('@url' => $url, '@code' => $response->code, '@data' => substr((string) $response->data, 0, 500)),
      WATCHDOG_ERROR);
    return FALSE;
  }
  return TRUE;
}

/**
 * Registers every facet used by every enabled search page.
 *
 * A page registers its own facets when it is saved, so a site built on one
 * backend has them already. Moving a project to another backend (Algolia to
 * Typesense) creates an empty index that nobody re-saves the pages against,
 * and on Typesense an unregistered facet fails the whole query. Re-indexing
 * calls this so the index matches the pages.
 *
 * @return array
 *   The facet keys that were registered.
 */
function quant_search_api_register_page_facets() {
  module_load_include('inc', 'quant_search', 'quant_search.theme');
  module_load_include('inc', 'quant_search', 'quant_search.pages');
  $keys = array();
  foreach (quant_search_page_load_all() as $page) {
    if (empty($page['status'])) {
      continue;
    }
    foreach (quant_search_compute_facet_keys($page['facets']) as $facet) {
      $keys[$facet['facet_key']] = $facet['facet_key'];
    }
  }
  $keys = array_values($keys);
  if ($keys) {
    quant_search_api_add_facets($keys);
  }
  return $keys;
}

/**
 * Registers facetable attribute names with the search backend.
 *
 * @param array $keys
 *   Flat list of attribute names. 'lang_code' and 'content_type' are always
 *   added.
 */
function quant_search_api_add_facets(array $keys) {
  $unique = array_values(array_unique(array_merge(array('lang_code', 'content_type'), $keys)));
  $response = quant_search_api_request('/search/facet', 'POST', $unique);
  if ($response->code == 200) {
    // The resolved backend carries the declared facet list; drop it so the
    // newly registered facets are visible on the next render rather than
    // after the cache expires.
    cache_clear_all('quant_search_backend:', 'cache', TRUE);
    return TRUE;
  }
  return FALSE;
}

/**
 * Returns index statistics, or FALSE.
 *
 * Response has ->index and ->settings when stats are available.
 */
function quant_search_api_stats() {
  $response = quant_search_api_request('/search', 'GET');
  if ($response->code == 200 && !empty($response->data)) {
    return json_decode($response->data);
  }
  return FALSE;
}
