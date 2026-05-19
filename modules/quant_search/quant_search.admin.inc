<?php

/**
 * @file
 * Quant Search admin pages.
 */

/**
 * Page callback: overview/status (fleshed out in a later task).
 */
function quant_search_admin_overview() {
  return array(
    '#markup' => t('Quant Search overview. Use the tabs to configure indexing and search pages.'),
  );
}

/**
 * Form: which entities and fields are indexed.
 */
function quant_search_entities_form($form, &$form_state) {
  $config = variable_get('quant_search_entities', array());

  $form['#tree'] = TRUE;
  $form['intro'] = array(
    '#markup' => '<p>' . t('Choose which content is pushed to the Quant search index.') . '</p>',
  );

  // Node configuration.
  $form['node'] = array(
    '#type' => 'fieldset',
    '#title' => t('Content (nodes)'),
    '#collapsible' => TRUE,
  );
  $form['node']['enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Index nodes'),
    '#default_value' => !empty($config['node']['enabled']),
  );
  $bundle_options = array();
  foreach (node_type_get_types() as $type => $info) {
    $bundle_options[$type] = $info->name;
  }
  $form['node']['bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Content types to index'),
    '#options' => $bundle_options,
    '#default_value' => isset($config['node']['bundles']) ? $config['node']['bundles'] : array(),
  );
  $form['node']['view_mode'] = array(
    '#type' => 'select',
    '#title' => t('View mode used to render the indexed body'),
    '#options' => quant_search_view_mode_options('node'),
    '#default_value' => isset($config['node']['view_mode']) ? $config['node']['view_mode'] : 'full',
  );
  $form['node']['title_token'] = array(
    '#type' => 'textfield',
    '#title' => t('Title token'),
    '#default_value' => isset($config['node']['title_token']) ? $config['node']['title_token'] : '[node:title]',
  );
  $form['node']['summary_token'] = array(
    '#type' => 'textfield',
    '#title' => t('Summary token'),
    '#default_value' => isset($config['node']['summary_token']) ? $config['node']['summary_token'] : '[node:summary]',
  );
  $form['node']['image_token'] = array(
    '#type' => 'textfield',
    '#title' => t('Image token'),
    '#default_value' => isset($config['node']['image_token']) ? $config['node']['image_token'] : '',
  );
  $form['node']['date_fields'] = array(
    '#type' => 'textfield',
    '#title' => t('Date fields'),
    '#description' => t('Comma-separated machine names of date fields to index as numeric timestamps (for date-range facets), e.g. field_event_session.'),
    '#default_value' => isset($config['node']['date_fields']) ? $config['node']['date_fields'] : '',
  );
  if (module_exists('token')) {
    $form['node']['token_help'] = array(
      '#theme' => 'token_tree_link',
      '#token_types' => array('node'),
    );
  }

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Save configuration'));
  return $form;
}

/**
 * Submit handler for quant_search_entities_form().
 */
function quant_search_entities_form_submit($form, &$form_state) {
  $v = $form_state['values'];
  $config = array(
    'node' => array(
      'enabled' => (bool) $v['node']['enabled'],
      'bundles' => array_values(array_filter($v['node']['bundles'])),
      'view_mode' => $v['node']['view_mode'],
      'title_token' => trim($v['node']['title_token']),
      'summary_token' => trim($v['node']['summary_token']),
      'image_token' => trim($v['node']['image_token']),
      'date_fields' => trim($v['node']['date_fields']),
    ),
  );
  variable_set('quant_search_entities', $config);
  drupal_set_message(t('Quant search indexing configuration saved.'));
}

/**
 * Returns view-mode options for an entity type.
 */
function quant_search_view_mode_options($entity_type) {
  $options = array('full' => t('Full content'), 'teaser' => t('Teaser'));
  $info = entity_get_info($entity_type);
  if (!empty($info['view modes'])) {
    foreach ($info['view modes'] as $mode => $mode_info) {
      $options[$mode] = $mode_info['label'];
    }
  }
  return $options;
}
