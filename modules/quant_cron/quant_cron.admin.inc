<?php

/**
 * @file
 * Admin pages and operations for Quant Cron.
 */

/**
 * Form constructor for the settings form.
 *
 * @ingroup forms
 */
function quant_cron_settings() {

  $form = array();

  _quant_get_seed_warnings($form);

  // If all fields are empty, assume they haven't used the cron form before
  // and show the message in a warning color rather than a status color.
  $css_class = 'warning';
  $fields = ['quant_cron_entity_node', 'quant_cron_entity_taxonomy', 'quant_cron_theme_assets', 'quant_cron_custom_routes_enabled', 'quant_cron_custom_routes'];
  foreach ($fields as $field) {
    if (!empty(variable_get($field))) {
      $css_class = 'status';
    }
  }
  $form['docs'] = array(
    '#type' => 'markup',
    '#prefix' => '<div class="messages ' . $css_class . '">',
    '#suffix' => '</div>',
    '#markup' => 'Learn more about this feature in the <a href="https://docs.quantcdn.io/docs/integrations/drupal/drupal-cron">Quant cron documentation</a>.'
  );

  $form['quant_cron_entity_node'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export nodes'),
    '#description' => t('Note: It is advised you limit cron export to a subset of nodes, e.g. a content type with limited content.'),
    '#default_value' => variable_get('quant_cron_entity_node', FALSE),
  );

  // Filter by node bundles.
  $types = node_type_get_types();

  $options = array();
  foreach ($types as $typename => $type) {
    $options[$typename] = $type->name;
  }

  $form['quant_cron_entity_node_bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Enabled bundles'),
    '#description' => t('Optionally, restrict to these content types.'),
    '#options' => $options,
    '#states' => array(
      'visible' => array(
        ':input[name="quant_cron_entity_node"]' => array('checked' => TRUE),
      ),
    ),
    '#default_value' => variable_get('quant_cron_entity_node_bundles', []),
  );

  $form['quant_cron_entity_taxonomy'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export taxonomy terms'),
    '#default_value' => variable_get('quant_cron_entity_taxonomy', FALSE),
  );

  $form['quant_cron_theme_assets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export theme assets'),
    '#default_value' => variable_get('quant_cron_theme_assets', FALSE),
  );

  if (module_exists('views')) {
    $form['quant_cron_views'] = array(
      '#type' => 'checkbox',
      '#title' => t('Export Views'),
      '#default_value' => variable_get('quant_cron_views', FALSE),
    );
  }

  $form['quant_cron_custom_routes_enabled'] = [
    '#type' => 'checkbox',
    '#title' => t('Custom routes'),
    '#description' => t('Exports custom list of routes.'),
    '#default_value' => variable_get('quant_cron_custom_routes_enabled', FALSE),
  ];

  $form['quant_cron_custom_routes'] = [
    '#type' => 'textarea',
    '#title' => t('Routes'),
    '#description' => t('Add routes to export, each on a new line. Routes must not include domain and start with a slash, e.g. /about-us'),
    '#states' => [
      'visible' => [
        ':input[name="routes"]' => ['checked' => TRUE],
      ],
    ],
    '#default_value' => variable_get('quant_cron_custom_routes', FALSE),
  ];

  return system_settings_form($form);
}
