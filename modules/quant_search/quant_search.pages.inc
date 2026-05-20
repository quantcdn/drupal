<?php

/**
 * @file
 * Quant Search: search-page CRUD and admin forms.
 */

/**
 * Loads one search page by id.
 *
 * @return array|false
 *   Associative array with keys: id, machine_name, label, status, route,
 *   expose_block, title, description, and the unpacked config keys
 *   (languages, bundles, manual_filters, facets, display).
 */
function quant_search_page_load($id) {
  $row = db_select('quant_search_page', 'p')->fields('p')->condition('id', $id)->execute()->fetchAssoc();
  return $row ? quant_search_page_unpack($row) : FALSE;
}

/**
 * Loads one search page by machine name.
 */
function quant_search_page_load_by_name($name) {
  $row = db_select('quant_search_page', 'p')->fields('p')->condition('machine_name', $name)->execute()->fetchAssoc();
  return $row ? quant_search_page_unpack($row) : FALSE;
}

/**
 * Loads all search pages.
 *
 * @param bool $enabled_only
 *   When TRUE, only status=1 pages are returned.
 *
 * @return array
 *   Search-page arrays keyed by id.
 */
function quant_search_page_load_all($enabled_only = FALSE) {
  $query = db_select('quant_search_page', 'p')->fields('p')->orderBy('label');
  if ($enabled_only) {
    $query->condition('status', 1);
  }
  $pages = array();
  foreach ($query->execute() as $row) {
    $pages[$row->id] = quant_search_page_unpack((array) $row);
  }
  return $pages;
}

/**
 * Expands a raw DB row: unserializes config into top-level keys.
 */
function quant_search_page_unpack($row) {
  $config = !empty($row['config']) ? unserialize($row['config']) : array();
  $config += array(
    'languages' => array(),
    'bundles' => array(),
    'manual_filters' => '',
    'facets' => array(),
    'display' => array(),
  );
  // Fill nested display defaults so callers that stored an empty display
  // still receive sensible defaults for every sub-key.
  $config['display'] += array(
    'results' => array(),
    'pagination' => array(),
  );
  $config['display']['results'] += array(
    'display_search' => 1,
    'display_stats' => 1,
    'show_clear_refinements' => 1,
  );
  $config['display']['pagination'] += array(
    'pagination_enabled' => 1,
    'per_page' => 20,
  );
  unset($row['config']);
  return array_merge($row, $config);
}

/**
 * Saves a search page (insert or update).
 *
 * @param array $page
 *   A page array as returned by quant_search_page_unpack().
 *
 * @return int
 *   The page id.
 */
function quant_search_page_save($page) {
  $record = array(
    'machine_name' => $page['machine_name'],
    'label' => $page['label'],
    'status' => !empty($page['status']) ? 1 : 0,
    'route' => isset($page['route']) ? $page['route'] : '',
    'expose_block' => !empty($page['expose_block']) ? 1 : 0,
    'title' => isset($page['title']) ? $page['title'] : '',
    'description' => isset($page['description']) ? $page['description'] : '',
    'config' => array(
      'languages' => isset($page['languages']) ? $page['languages'] : array(),
      'bundles' => isset($page['bundles']) ? $page['bundles'] : array(),
      'manual_filters' => isset($page['manual_filters']) ? $page['manual_filters'] : '',
      'facets' => isset($page['facets']) ? $page['facets'] : array(),
      'display' => isset($page['display']) ? $page['display'] : array(),
    ),
  );
  if (!empty($page['id'])) {
    $record['id'] = $page['id'];
    drupal_write_record('quant_search_page', $record, 'id');
  }
  else {
    drupal_write_record('quant_search_page', $record);
  }
  return $record['id'];
}

/**
 * Deletes a search page.
 */
function quant_search_page_delete($id) {
  db_delete('quant_search_page')->condition('id', $id)->execute();
}
