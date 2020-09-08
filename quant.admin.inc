<?php

/**
 * @file
 * Admin pages and operations for Quant.
 */

/**
 * Form constructor for the configuration form.
 *
 * @ingroup forms
 */
function quant_config() {
  global $base_url;

  $form['quant_draft_revisions'] = array(
    '#type' => 'checkbox',
    '#title' => t('Push draft revisions'),
    '#description' => t('Support draft node revisions. <i>experimental - this might have compatibility issues with your module setup</i>.'),
    '#default_value' => variable_get('quant_draft_revisions', FALSE),
  );

  $form['quant_rewrite_relative'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable relative paths'),
    '#description' => t('Ensure all assets are rewritten as relative paths.'),
    '#default_value' => variable_get('quant_rewrite_relative', FALSE),
  );

  $form['quant_proxy_override'] = array(
    '#type' => 'checkbox',
    '#title' => t('Override existing proxies'),
    '#description' => t('Overrides proxies created via the dashboard.'),
    '#default_value' => variable_get('quant_proxy_override', FALSE),
  );

  $form['quant_verbose_logging'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable verbose logging'),
    '#description' => t('Adds more information to logging outputs for each API call.'),
    '#default_value' => variable_get('quant_verbose_logging', FALSE),
  );

  $form['quant_base_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Webserver URL'),
    '#description' => t('Provide the FQDN that internal requests may route to. e.g: http://localhost, http://nginx:8080, http:127.0.0.1. <a href="#">More info</a>'),
    '#default_value' => variable_get('quant_base_url'),
  );

  $form['quant_hostname'] = array(
    '#type' => 'textfield',
    '#title' => t('Hostname'),
    '#description' => t('Optionally provide the expected hostname for content served via quant, this ensures that absolute links in content point to the correct domain. This ensures absolute links in content point to the correct domain. e.g: www.example.com <a href="#">More info</a>'),
    '#default_value' => variable_get('quant_hostname', $base_url),
  );

  return system_settings_form($form);
}

/**
 * Form constructor for the seed form.
 *
 * @ingroup forms
 */
function quant_seed_settings() {
  $form = array();

  _quant_get_seed_warnings($form);

  $form['quant_seed_entity_node'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export nodes'),
    '#default_value' => variable_get('quant_seed_entity_node', FALSE),
  );

  // Filter by node bundles.
  $types = node_type_get_types();

  $options = array();
  foreach ($types as $typename => $type) {
    $options[$typename] = $type->name;
  }

  $form['quant_seed_entity_node_bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Enabled bundles'),
    '#description' => t('Optionally restrict to these content types.'),
    '#options' => $options,
    '#states' => array(
      'visible' => array(
        ':input[name="quant_seed_entity_node"]' => array('checked' => TRUE),
      ),
    ),
  );

  $form['quant_seed_entity_taxonomy'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export taxonomy terms'),
    '#default_value' => variable_get('quant_seed_entity_taxonomy', FALSE),
  );

  $form['quant_seed_theme_assets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export theme assets'),
    '#default_value' => variable_get('quant_seed_theme_assets', FALSE),
  );

  $form['quant_seed_views'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export Views'),
    '#default_value' => variable_get('quant_seed_views', FALSE),
  );

  // @TODO - Should we support user pages?
  $form['entity_users'] = array();

  // @TODO - add media entity support.
  $form['entity_media'] = array();

  $form['routes'] = [
    '#type' => 'checkbox',
    '#title' => t('Custom routes'),
    '#description' => t('Exports custom list of routes.'),
  ];

  $form['quant_custom_routes'] = [
    '#type' => 'textarea',
    '#title' => t('Routes'),
    '#description' => t('Add routes to export, each on a new line. Routes must not include domain and start with a slash, e.g: /about-us'),
    '#states' => [
      'visible' => [
        ':input[name="routes"]' => ['checked' => TRUE],
      ],
    ],
    '#default_value' => variable_get('quant_custom_routes', FALSE),
  ];


  $form['quant_seed'] = array(
    '#type' => 'checkbox',
    '#title' => t('Trigger the batch'),
    '#description' => t('<strong>Note:</strong> This will attempt to trigger the seed from the UI, depending on the size of your site and PHP configuration this may not work.'),
    '#weight' => 998,
  );

  $form = system_settings_form($form);

  $form['actions']['#weight'] = 999;

  $form['#submit'][] = '_quant_seed_prepare';

  return $form;
}
