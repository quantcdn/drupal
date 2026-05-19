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
    return drupal_json_decode($response->data, FALSE);
  }
  watchdog('quant_search', 'Project lookup failed: @code', array('@code' => $response->code), WATCHDOG_WARNING);
  return FALSE;
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
 * Registers facetable attribute names with the search backend.
 *
 * @param array $keys
 *   Flat list of attribute names. 'lang_code' and 'content_type' are always
 *   added.
 */
function quant_search_api_add_facets(array $keys) {
  $unique = array_values(array_unique(array_merge(array('lang_code', 'content_type'), $keys)));
  $response = quant_search_api_request('/search/facet', 'POST', $unique);
  return $response->code == 200;
}

/**
 * Returns index statistics, or FALSE.
 *
 * Response has ->index and ->settings when stats are available.
 */
function quant_search_api_stats() {
  $response = quant_search_api_request('/search', 'GET');
  if ($response->code == 200 && !empty($response->data)) {
    return drupal_json_decode($response->data, FALSE);
  }
  return FALSE;
}
