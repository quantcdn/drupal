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

  $form['quant_enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Track content changes'),
    '#description' => t('Automatically push content changes to Quant (recommended).'),
    '#default_value' => variable_get('quant_enabled', FALSE),
  );

  $form['tracking_fieldset'] = [
    '#type' => 'fieldset',
    '#title' => t('Tracked entities'),
    '#states' => [
      'visible' => [
        ':input[name="quant_enabled"]' => ['checked' => TRUE],
      ],
    ],
  ];

  $form['tracking_fieldset']['quant_enabled_nodes'] = [
    '#type' => 'checkbox',
    '#title' => t('Nodes'),
    '#default_value' => variable_get('quant_enabled_nodes'),
  ];

  $form['tracking_fieldset']['quant_enabled_taxonomy'] = [
    '#type' => 'checkbox',
    '#title' => t('Taxonomy Terms'),
    '#default_value' => variable_get('quant_enabled_taxonomy'),
  ];

  $form['tracking_fieldset']['quant_enabled_views'] = [
    '#type' => 'checkbox',
    '#title' => t('Views'),
    '#default_value' => variable_get('quant_enabled_views'),
  ];

  $form['disable_content_drafts'] = array(
    '#type' => 'checkbox',
    '#title' => t('Disable content drafts'),
    '#description' => t('Prevent draft content from being sent to Quant.'),
    '#default_value' => variable_get('disable_content_drafts', FALSE),
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
    '#default_value' => variable_get('quant_base_url', 'http://localhost'),
  );

  $form['quant_hostname'] = array(
    '#type' => 'textfield',
    '#title' => t('Hostname'),
    '#description' => t('Optionally provide the expected hostname for content served via quant, this ensures that absolute links in content point to the correct domain. This ensures absolute links in content point to the correct domain. e.g: www.example.com <a href="#">More info</a>'),
    '#default_value' => variable_get('quant_hostname', 'www.example.com'),
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

  $form['quant_robots'] = [
    '#type' => 'checkbox',
    '#title' => t('Export robots.txt'),
    '#default_value' => variable_geT('quant_robots', FALSE),
  ];

  $form['trigger_quant_seed'] = array(
    '#type' => 'checkbox',
    '#title' => t('Trigger the batch'),
    '#description' => t('<strong>Note:</strong> This will attempt to trigger the seed from the UI, depending on the size of your site and PHP configuration this may not work.'),
    '#weight' => 998,
  );

  $form = system_settings_form($form);

  $form['actions']['#weight'] = 999;

  $form['#submit'][] = '_quant_seed_prepare';
  $form['#submit'][] = '_quant_queue_batch';

  return $form;
}

/**
 * Present items that are queued for quant sending.
 */
function quant_queue_page() {
  $items = quant_get_queue(TRUE);
  $rows = [];

  $header = array(
    t('Item ID'),
    t('Claimed/Expiration'),
    t('Created'),
    t('Content/Data'),
  );

  foreach ($items as &$item) {
    if ($item['expire'] > 0) {
      $item['expire'] = t("Claimed: expires %expire", array('%expire' => date('r', $item['expire'])));
    }
    else {
      $item['expire'] = t('Unclaimed');
    }
    $item['created'] = date('r', $item['created']);
    list($op, $context) = unserialize($item['data']);
    $item['content'] = t('<b>Callback:</b> %op<br /><b>URL:</b> %arg', array(
      '%op' => $op,
      '%arg' => reset($context),
    ));
    unset($item['data']);
    $rows[] = $item;
  }

  if (!empty($items)) {
    $queue = quant_get_queue();
    $build['desc'] = array(
      '#markup' => t('There are <b>%total</b> items in the queue.<br/><br />', array(
        '%total' => $queue->numberOfItems(),
      )),
    );
  }

  $build['pager_table'] = array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('There are no items in the queue.'),
  );

  $build['pager'] = array(
    '#theme' => 'pager',
  );

  return $build;
}
