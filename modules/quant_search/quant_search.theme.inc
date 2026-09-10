<?php

/**
 * @file
 * Quant Search: rendering helpers shared by routes and blocks.
 */

/**
 * Splits an attached-assets textarea value into #attached entries.
 *
 * One path or URL per line. URLs starting with http(s):// are tagged as
 * 'external'; everything else passes through as a path string (D7's
 * #attached treats bare strings as files).
 *
 * @param string $raw
 *   Raw textarea value.
 *
 * @return array
 *   List of entries suitable for $build['#attached']['js' or 'css'].
 */
function quant_search_split_attached($raw) {
  $out = array();
  if (!is_string($raw) || $raw === '') {
    return $out;
  }
  foreach (preg_split('/\R+/', $raw) as $line) {
    $line = trim($line);
    if ($line === '') {
      continue;
    }
    if (preg_match('#^https?://#i', $line)) {
      $out[] = array('type' => 'external', 'data' => $line);
    }
    else {
      $out[] = $line;
    }
  }
  return $out;
}

/**
 * Computes the index attribute name and container id for each facet.
 *
 * @param array $facets
 *   Facet definitions from a search page.
 *
 * @return array
 *   Each facet augmented with 'facet_key' (index attribute) and
 *   'facet_container' (unique id suffix).
 */
function quant_search_compute_facet_keys($facets) {
  $out = array();
  foreach (array_values($facets) as $i => $facet) {
    $lang = !empty($facet['language']) ? $facet['language'] : 'en';
    switch ($facet['type']) {
      case 'taxonomy':
        $key = $facet['vocabulary'] . '_' . $lang;
        break;

      case 'content_type':
        $key = 'content_type_' . $lang;
        break;

      case 'language':
        $key = 'language_' . $lang;
        break;

      case 'date_range':
        $key = $facet['date_field'];
        break;

      case 'custom':
      default:
        $key = $facet['custom_key'];
        break;
    }
    $facet['facet_key'] = $key;
    $facet['facet_container'] = $key . '_' . $i;
    $out[] = $facet;
  }
  return $out;
}

/**
 * Builds the search-backend filter string for a page.
 */
function quant_search_build_filters($page, $backend) {
  $typesense = $backend['backend'] === 'typesense';
  $clauses = array();
  foreach (array('lang_code' => 'languages', 'content_type' => 'bundles') as $field => $key) {
    if (empty($page[$key])) {
      continue;
    }
    $parts = array();
    foreach ($page[$key] as $value) {
      $parts[] = quant_search_filter_equals($field, $value, $typesense);
    }
    $clauses[] = '(' . implode($typesense ? ' || ' : ' OR ', $parts) . ')';
  }
  // The manual filter is documented as passed through verbatim, so it must
  // already be written in the syntax the project's backend expects.
  if (!empty($page['manual_filters'])) {
    $clauses[] = $page['manual_filters'];
  }
  return implode($typesense ? ' && ' : ' AND ', $clauses);
}

/**
 * One equality clause, in Algolia or Typesense syntax.
 *
 * Typesense quotes string values with backticks, so a value containing one
 * cannot be expressed and is dropped; these values are machine names
 * (language codes, bundle names), so this is unreachable in practice and
 * exists to keep an injected value from changing the filter's meaning.
 *
 * @param string $field
 *   The attribute name.
 * @param string $value
 *   The value to match.
 * @param bool $typesense
 *   TRUE for Typesense syntax, FALSE for Algolia.
 *
 * @return string
 *   The clause, or '' when the value cannot be expressed.
 */
function quant_search_filter_equals($field, $value, $typesense) {
  if (!$typesense) {
    return $field . ":'" . str_replace("'", '', $value) . "'";
  }
  if (strpos($value, '`') !== FALSE) {
    return '';
  }
  return $field . ':=`' . $value . '`';
}

/**
 * Removes facets the search backend cannot facet on.
 *
 * Typesense answers a query that facets on an undeclared field with a 404,
 * which fails the whole batched request and leaves the listing empty; Algolia
 * ignores it and returns results. Dropping the facet degrades one dropdown
 * instead of the page. Date facets are never dropped: the date widget filters
 * by range and never asks the backend to facet, and its container has to
 * exist for the presets to render.
 *
 * @param array $facets
 *   Facets from quant_search_compute_facet_keys().
 * @param array $backend
 *   As returned by quant_search_api_backend().
 *
 * @return array
 *   The facets that are safe to render.
 */
function quant_search_filter_declared_facets(array $facets, array $backend) {
  if (empty($backend['facets_declared'])) {
    return $facets;
  }
  $declared = array_flip($backend['facets_declared']);
  $kept = array();
  $skipped = array();
  foreach ($facets as $facet) {
    $is_date = (isset($facet['widget']) && $facet['widget'] === 'date')
      || (isset($facet['type']) && $facet['type'] === 'date_range');
    if ($is_date || isset($declared[$facet['facet_key']])) {
      $kept[] = $facet;
    }
    else {
      $skipped[] = $facet['facet_key'];
    }
  }
  if ($skipped) {
    watchdog('quant_search',
      'Skipped facet(s) not registered with the search index: @keys. Re-index or save the search page to register them.',
      array('@keys' => implode(', ', $skipped)), WATCHDOG_WARNING);
  }
  return $kept;
}

/**
 * The vendor client script plus the module's client factory, for a backend.
 *
 * @param array $backend
 *   As returned by quant_search_api_backend().
 *
 * @return array
 *   #attached js entries, in load order.
 */
function quant_search_client_scripts(array $backend) {
  $vendor = $backend['backend'] === 'typesense'
    ? 'https://cdn.jsdelivr.net/npm/typesense-instantsearch-adapter@2/dist/typesense-instantsearch-adapter.min.js'
    : 'https://cdn.jsdelivr.net/npm/algoliasearch@4/dist/algoliasearch-lite.umd.js';
  return array(
    array('type' => 'external', 'data' => $vendor),
    drupal_get_path('module', 'quant_search') . '/js/quant-search-client.js',
  );
}

/**
 * Builds the render array for a search page (route or block).
 *
 * @param array $page
 *   A loaded search page.
 *
 * @return array
 *   A renderable array.
 */
function quant_search_render($page) {
  module_load_include('inc', 'quant_search', 'quant_search.api');
  $backend = quant_search_api_backend();
  if (!$backend) {
    return array('#markup' => '<p>' . t('Search is not currently available.') . '</p>');
  }
  $facets = quant_search_filter_declared_facets(quant_search_compute_facet_keys($page['facets']), $backend);
  $instance = $page['machine_name'];

  $settings = array(
    'quantSearch' => array(
      $instance => $backend + array(
        'instance' => $instance,
        'filters' => quant_search_build_filters($page, $backend),
        'facets' => $facets,
        'display' => $page['display'],
      ),
    ),
  );

  // Let other modules and themes mutate the per-instance settings before
  // they ship to the browser. The reference is to the inner per-instance
  // array so implementations do not have to dig through the wrapper.
  drupal_alter('quant_search_settings', $settings['quantSearch'][$instance], $page);

  $module_path = drupal_get_path('module', 'quant_search');
  $build = array(
    '#theme' => 'quant_search_page',
    '#page' => $page,
    '#facets' => $facets,
    '#instance' => $instance,
    '#attached' => array(
      'js' => array_merge(quant_search_client_scripts($backend), array(
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.js@4'),
        $module_path . '/js/quant-search.js',
        array('type' => 'setting', 'data' => $settings),
      )),
      'css' => array(
        $module_path . '/css/quant-search.css',
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.css@7/themes/algolia-min.css'),
      ),
    ),
  );

  // Per-page attached assets — paths or external URLs, one per line.
  foreach (quant_search_split_attached($page['display']['attached_js']) as $entry) {
    $build['#attached']['js'][] = $entry;
  }
  foreach (quant_search_split_attached($page['display']['attached_css']) as $entry) {
    $build['#attached']['css'][] = $entry;
  }

  return $build;
}
