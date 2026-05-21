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
    'layout' => 'card',
    'attached_js' => '',
    'attached_css' => '',
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

/**
 * Page callback: lists search pages.
 */
function quant_search_pages_list() {
  $header = array(t('Label'), t('Machine name'), t('Route'), t('Block'), t('Status'), t('Operations'));
  $rows = array();
  foreach (quant_search_page_load_all() as $page) {
    $route = $page['route'] !== '' ? l($page['route'], $page['route']) : t('(block only)');
    $rows[] = array(
      check_plain($page['label']),
      check_plain($page['machine_name']),
      $route,
      $page['expose_block'] ? t('Yes') : t('No'),
      $page['status'] ? t('Enabled') : t('Disabled'),
      l(t('Edit'), 'admin/config/services/quant/search/pages/' . $page['id'] . '/edit')
        . ' | ' . l(t('Delete'), 'admin/config/services/quant/search/pages/' . $page['id'] . '/delete'),
    );
  }
  return array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('No search pages yet. Use "Add search page" to create one.'),
  );
}

/**
 * Form: add/edit a search page.
 */
function quant_search_page_form($form, &$form_state, $id = NULL) {
  $page = $id ? quant_search_page_load($id) : NULL;
  if ($id && !$page) {
    drupal_not_found();
    drupal_exit();
  }
  $form['#tree'] = TRUE;
  $form_state['page_id'] = $page ? $page['id'] : NULL;

  // Seed the working facet list once.
  if (!isset($form_state['facets'])) {
    $form_state['facets'] = $page ? array_values($page['facets']) : array();
  }

  $form['label'] = array(
    '#type' => 'textfield',
    '#title' => t('Label'),
    '#required' => TRUE,
    '#default_value' => $page ? $page['label'] : '',
  );
  $form['machine_name'] = array(
    '#type' => 'machine_name',
    '#default_value' => $page ? $page['machine_name'] : '',
    '#machine_name' => array('exists' => 'quant_search_page_load_by_name', 'source' => array('label')),
    '#disabled' => (bool) $page,
  );
  $form['status'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enabled'),
    '#default_value' => $page ? $page['status'] : 1,
  );
  $form['route'] = array(
    '#type' => 'textfield',
    '#title' => t('Route'),
    '#description' => t('URL path for this search page, no leading slash, e.g. search or whats-on. Leave empty for a block-only page.'),
    '#default_value' => $page ? $page['route'] : '',
  );
  $form['expose_block'] = array(
    '#type' => 'checkbox',
    '#title' => t('Also expose this search page as a block'),
    '#default_value' => $page ? $page['expose_block'] : 0,
  );
  $form['title'] = array(
    '#type' => 'textfield',
    '#title' => t('Page title'),
    '#default_value' => $page ? $page['title'] : '',
  );
  $form['description'] = array(
    '#type' => 'textarea',
    '#title' => t('Description'),
    '#default_value' => $page ? $page['description'] : '',
  );

  // Content scoping.
  $form['scope'] = array('#type' => 'fieldset', '#title' => t('Result scope'), '#collapsible' => TRUE);
  $bundle_options = array();
  foreach (node_type_get_types() as $type => $info) {
    $bundle_options[$type] = $info->name;
  }
  $form['scope']['bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Limit to content types'),
    '#options' => $bundle_options,
    '#default_value' => $page ? $page['bundles'] : array(),
  );
  $lang_options = array();
  foreach (language_list() as $code => $lang) {
    $lang_options[$code] = $lang->name;
  }
  $form['scope']['languages'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Limit to languages'),
    '#options' => $lang_options,
    '#default_value' => $page ? $page['languages'] : array(),
  );
  $form['scope']['manual_filters'] = array(
    '#type' => 'textfield',
    '#title' => t('Manual filter string'),
    '#description' => t('Raw search filter expression, passed through verbatim.'),
    '#default_value' => $page ? $page['manual_filters'] : '',
  );

  // Facets — rebuilt on AJAX.
  $form['facets'] = array(
    '#type' => 'fieldset',
    '#title' => t('Facets'),
    '#prefix' => '<div id="quant-search-facets-wrapper">',
    '#suffix' => '</div>',
  );
  $form['facets']['rows'] = quant_search_page_facet_rows($form_state['facets']);
  $form['facets']['add'] = array(
    '#type' => 'submit',
    '#value' => t('Add facet'),
    '#submit' => array('quant_search_page_form_add_facet'),
    '#ajax' => array('callback' => 'quant_search_page_form_facets_ajax', 'wrapper' => 'quant-search-facets-wrapper'),
    '#limit_validation_errors' => array(),
  );

  // Display options.
  $form['display'] = array('#type' => 'fieldset', '#title' => t('Display'), '#collapsible' => TRUE);
  $d = $page ? $page['display'] : array();
  $form['display']['display_search'] = array(
    '#type' => 'checkbox', '#title' => t('Show search box'),
    '#default_value' => isset($d['results']['display_search']) ? $d['results']['display_search'] : 1,
  );
  $form['display']['display_stats'] = array(
    '#type' => 'checkbox', '#title' => t('Show result stats'),
    '#default_value' => isset($d['results']['display_stats']) ? $d['results']['display_stats'] : 1,
  );
  $form['display']['show_clear_refinements'] = array(
    '#type' => 'checkbox', '#title' => t('Show "clear refinements"'),
    '#default_value' => isset($d['results']['show_clear_refinements']) ? $d['results']['show_clear_refinements'] : 1,
  );
  $form['display']['pagination_enabled'] = array(
    '#type' => 'checkbox', '#title' => t('Enable pagination'),
    '#default_value' => isset($d['pagination']['pagination_enabled']) ? $d['pagination']['pagination_enabled'] : 1,
  );
  $form['display']['per_page'] = array(
    '#type' => 'textfield', '#title' => t('Results per page'), '#size' => 5,
    '#default_value' => isset($d['pagination']['per_page']) ? $d['pagination']['per_page'] : 20,
  );
  $form['display']['layout'] = array(
    '#type' => 'select',
    '#title' => t('Default results layout'),
    '#options' => array(
      'card' => t('Cards'),
      'list' => t('List'),
    ),
    '#default_value' => isset($d['layout']) ? $d['layout'] : 'card',
    '#description' => t('Visitors can switch on the page; this sets the initial layout.'),
  );
  $form['display']['attached_js'] = array(
    '#type' => 'textarea',
    '#title' => t('Additional JS to attach'),
    '#description' => t('One path or URL per line. External URLs (http/https) are detected automatically; everything else is treated as a path relative to the Drupal root.'),
    '#default_value' => isset($d['attached_js']) ? $d['attached_js'] : '',
    '#rows' => 3,
  );
  $form['display']['attached_css'] = array(
    '#type' => 'textarea',
    '#title' => t('Additional CSS to attach'),
    '#description' => t('One path or URL per line.'),
    '#default_value' => isset($d['attached_css']) ? $d['attached_css'] : '',
    '#rows' => 3,
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Save search page'));
  return $form;
}

/**
 * Builds the facet rows render array from the working facet list.
 *
 * Each facet row exposes: heading, type, taxonomy vocabulary (for taxonomy),
 * date field (for date_range), widget, language, limit, weight, and a remove
 * button. Facet types: taxonomy, content_type, language, date_range, custom.
 */
function quant_search_page_facet_rows($facets) {
  $rows = array();
  $type_options = array(
    'taxonomy' => t('Taxonomy'),
    'content_type' => t('Content type'),
    'language' => t('Language'),
    'date_range' => t('Date range'),
    'custom' => t('Custom attribute'),
  );
  $widget_options = array(
    'checkbox' => t('Checkbox list'),
    'select' => t('Select dropdown'),
    'menu' => t('Menu'),
    'radio' => t('Radio buttons (single-select)'),
    'pills' => t('Pill buttons (multi-select)'),
    'date' => t('Date range inputs'),
  );
  $vocab_options = array();
  foreach (taxonomy_get_vocabularies() as $vocab) {
    $vocab_options[$vocab->machine_name] = $vocab->name;
  }
  foreach ($facets as $i => $facet) {
    $rows[$i] = array(
      'heading' => array('#type' => 'textfield', '#title' => t('Heading'), '#default_value' => isset($facet['heading']) ? $facet['heading'] : ''),
      'type' => array('#type' => 'select', '#title' => t('Type'), '#options' => $type_options, '#default_value' => isset($facet['type']) ? $facet['type'] : 'taxonomy'),
      'vocabulary' => array('#type' => 'select', '#title' => t('Vocabulary'), '#options' => $vocab_options, '#default_value' => isset($facet['vocabulary']) ? $facet['vocabulary'] : ''),
      'date_field' => array('#type' => 'textfield', '#title' => t('Date field'), '#default_value' => isset($facet['date_field']) ? $facet['date_field'] : '', '#description' => t('Indexed date field machine name.')),
      'custom_key' => array('#type' => 'textfield', '#title' => t('Custom attribute'), '#default_value' => isset($facet['custom_key']) ? $facet['custom_key'] : ''),
      'widget' => array('#type' => 'select', '#title' => t('Widget'), '#options' => $widget_options, '#default_value' => isset($facet['widget']) ? $facet['widget'] : 'checkbox'),
      'language' => array('#type' => 'textfield', '#title' => t('Language'), '#size' => 6, '#default_value' => isset($facet['language']) ? $facet['language'] : 'en'),
      'limit' => array('#type' => 'textfield', '#title' => t('Limit'), '#size' => 4, '#default_value' => isset($facet['limit']) ? $facet['limit'] : 10),
      'weight' => array('#type' => 'weight', '#title' => t('Weight'), '#default_value' => isset($facet['weight']) ? $facet['weight'] : 0),
      'remove' => array(
        '#type' => 'submit',
        '#name' => 'remove_facet_' . $i,
        '#value' => t('Remove'),
        '#submit' => array('quant_search_page_form_remove_facet'),
        '#ajax' => array('callback' => 'quant_search_page_form_facets_ajax', 'wrapper' => 'quant-search-facets-wrapper'),
        '#limit_validation_errors' => array(),
        '#facet_index' => $i,
      ),
    );
  }
  return $rows;
}

/**
 * AJAX callback: returns the rebuilt facets fieldset.
 */
function quant_search_page_form_facets_ajax($form, &$form_state) {
  return $form['facets'];
}

/**
 * Submit: append an empty facet row.
 */
function quant_search_page_form_add_facet($form, &$form_state) {
  $form_state['facets'] = quant_search_page_collect_facets($form_state);
  $form_state['facets'][] = array();
  $form_state['rebuild'] = TRUE;
}

/**
 * Submit: remove a facet row.
 */
function quant_search_page_form_remove_facet($form, &$form_state) {
  $form_state['facets'] = quant_search_page_collect_facets($form_state);
  $index = $form_state['triggering_element']['#facet_index'];
  unset($form_state['facets'][$index]);
  $form_state['facets'] = array_values($form_state['facets']);
  $form_state['rebuild'] = TRUE;
}

/**
 * Reads the current facet values out of submitted form input.
 */
function quant_search_page_collect_facets($form_state) {
  $facets = array();
  if (!empty($form_state['values']['facets']['rows'])) {
    foreach ($form_state['values']['facets']['rows'] as $row) {
      $facets[] = array(
        'heading' => $row['heading'],
        'type' => $row['type'],
        'vocabulary' => $row['vocabulary'],
        'date_field' => $row['date_field'],
        'custom_key' => $row['custom_key'],
        'widget' => $row['widget'],
        'language' => $row['language'],
        'limit' => (int) $row['limit'],
        'weight' => (int) $row['weight'],
      );
    }
  }
  return $facets;
}

/**
 * Submit handler: persists the search page.
 */
function quant_search_page_form_submit($form, &$form_state) {
  module_load_include('inc', 'quant_search', 'quant_search.api');
  $v = $form_state['values'];
  $facets = quant_search_page_collect_facets($form_state);
  usort($facets, function ($a, $b) {
    return $a['weight'] - $b['weight'];
  });
  $page = array(
    'id' => $form_state['page_id'],
    'machine_name' => $v['machine_name'],
    'label' => $v['label'],
    'status' => $v['status'],
    'route' => trim($v['route'], '/ '),
    'expose_block' => $v['expose_block'],
    'title' => $v['title'],
    'description' => $v['description'],
    'languages' => array_values(array_filter($v['scope']['languages'])),
    'bundles' => array_values(array_filter($v['scope']['bundles'])),
    'manual_filters' => $v['scope']['manual_filters'],
    'facets' => $facets,
    'display' => array(
      'layout' => $v['display']['layout'],
      'attached_js' => trim($v['display']['attached_js']),
      'attached_css' => trim($v['display']['attached_css']),
      'results' => array(
        'display_search' => $v['display']['display_search'],
        'display_stats' => $v['display']['display_stats'],
        'show_clear_refinements' => $v['display']['show_clear_refinements'],
      ),
      'pagination' => array(
        'pagination_enabled' => $v['display']['pagination_enabled'],
        'per_page' => (int) $v['display']['per_page'],
      ),
    ),
  );
  quant_search_page_save($page);

  // Register facet keys with the backend.
  module_load_include('inc', 'quant_search', 'quant_search.theme');
  $keys = array();
  foreach (quant_search_compute_facet_keys($facets) as $f) {
    $keys[] = $f['facet_key'];
  }
  quant_search_api_add_facets($keys);

  // Rebuild the menu so the route registers, and flush block info.
  variable_set('menu_rebuild_needed', TRUE);
  cache_clear_all('*', 'cache_block', TRUE);

  drupal_set_message(t('Search page saved.'));
  $form_state['redirect'] = 'admin/config/services/quant/search/pages';
}

/**
 * Form: confirm deleting a search page.
 */
function quant_search_page_delete_form($form, &$form_state, $id) {
  $page = quant_search_page_load($id);
  if (!$page) {
    drupal_not_found();
    drupal_exit();
  }
  $form_state['page_id'] = $id;
  return confirm_form(
    array(),
    t('Delete search page %label?', array('%label' => $page['label'])),
    'admin/config/services/quant/search/pages',
    t('This cannot be undone.'),
    t('Delete'),
    t('Cancel')
  );
}

/**
 * Submit handler: deletes the search page.
 */
function quant_search_page_delete_form_submit($form, &$form_state) {
  quant_search_page_delete($form_state['page_id']);
  variable_set('menu_rebuild_needed', TRUE);
  drupal_set_message(t('Search page deleted.'));
  $form_state['redirect'] = 'admin/config/services/quant/search/pages';
}
