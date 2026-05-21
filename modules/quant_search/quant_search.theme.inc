<?php

/**
 * @file
 * Quant Search: rendering helpers shared by routes and blocks.
 */

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
function quant_search_build_filters($page) {
  $clauses = array();
  if (!empty($page['languages'])) {
    $parts = array();
    foreach ($page['languages'] as $lang) {
      $parts[] = "lang_code:'" . $lang . "'";
    }
    $clauses[] = '(' . implode(' OR ', $parts) . ')';
  }
  if (!empty($page['bundles'])) {
    $parts = array();
    foreach ($page['bundles'] as $bundle) {
      $parts[] = "content_type:'" . $bundle . "'";
    }
    $clauses[] = '(' . implode(' OR ', $parts) . ')';
  }
  if (!empty($page['manual_filters'])) {
    $clauses[] = $page['manual_filters'];
  }
  return implode(' AND ', $clauses);
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
  $project = quant_search_api_project();
  if (!$project || empty($project->config->search_enabled)) {
    return array('#markup' => '<p>' . t('Search is not currently available.') . '</p>');
  }
  $index = $project->config->search_index;
  $facets = quant_search_compute_facet_keys($page['facets']);
  $instance = $page['machine_name'];

  $settings = array(
    'quantSearch' => array(
      $instance => array(
        'instance' => $instance,
        'app_id' => $index->algolia_application_id,
        'read_key' => $index->algolia_read_key,
        'index' => $index->algolia_index,
        'filters' => quant_search_build_filters($page),
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
  return array(
    '#theme' => 'quant_search_page',
    '#page' => $page,
    '#facets' => $facets,
    '#instance' => $instance,
    '#attached' => array(
      'js' => array(
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/algoliasearch@4/dist/algoliasearch-lite.umd.js'),
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.js@4'),
        $module_path . '/js/quant-search.js',
        array('type' => 'setting', 'data' => $settings),
      ),
      'css' => array(
        $module_path . '/css/quant-search.css',
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.css@7/themes/algolia-min.css'),
      ),
    ),
  );
}
