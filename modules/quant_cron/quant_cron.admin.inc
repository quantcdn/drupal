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
  $config = config('quant_cron.settings');

  $form = array();
  $form['#config'] = 'quant_cron.settings';

  _quant_get_seed_warnings($form);

  $form['quant_cron_entity_node'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export nodes'),
    '#description' => t('Note: It is advised you limit cron export to a subset of nodes (e.g a content type with limited content).'),
    '#default_value' => $config->get('quant_cron_entity_node'),
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
    '#description' => t('Optionally restrict to these content types.'),
    '#options' => $options,
    '#states' => array(
      'visible' => array(
        ':input[name="quant_cron_entity_node"]' => array('checked' => TRUE),
      ),
    ),
    '#default_value' => $config->get('quant_cron_entity_node_bundles'),
  );

  $form['quant_cron_entity_taxonomy'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export taxonomy terms'),
    '#default_value' => $config->get('quant_cron_entity_taxonomy'),
  );

  $form['quant_cron_theme_assets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export theme assets'),
    '#default_value' => $config->get('quant_cron_theme_assets'),
  );

  $form['quant_cron_views'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export Views'),
    '#default_value' => $config->get('quant_cron_views'),
  );

  $form['quant_cron_custom_routes_enabled'] = [
    '#type' => 'checkbox',
    '#title' => t('Custom routes'),
    '#description' => t('Exports custom list of routes.'),
    '#default_value' => $config->get('quant_cron_custom_routes_enabled'),
  ];

  $form['quant_cron_custom_routes'] = [
    '#type' => 'textarea',
    '#title' => t('Routes'),
    '#description' => t('Add routes to export, each on a new line. Routes must not include domain and start with a slash, e.g: /about-us'),
    '#states' => [
      'visible' => [
        ':input[name="routes"]' => ['checked' => TRUE],
      ],
    ],
    '#default_value' => $config->get('quant_cron_custom_routes'),
  ];

  return system_settings_form($form);
}
