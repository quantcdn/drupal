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
  $config = config('quant.settings');

  $form['#config'] = 'quant.settings';
  $form['quant_enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Track content changes'),
    '#description' => t('Automatically push content changes to Quant (recommended).'),
    '#default_value' => $config->get('quant_enabled'),
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
    '#default_value' => $config->get('quant_enabled_nodes'),
  ];

  $form['tracking_fieldset']['quant_enabled_taxonomy'] = [
    '#type' => 'checkbox',
    '#title' => t('Taxonomy Terms'),
    '#default_value' => $config->get('quant_enabled_taxonomy'),
  ];

  $form['tracking_fieldset']['quant_enabled_views'] = [
    '#type' => 'checkbox',
    '#title' => t('Views'),
    '#default_value' => $config->get('quant_enabled_views'),
  ];

  // TODO This variable was probably removed in Backdrop without replacement.
  $form['disable_content_drafts'] = array(
    '#type' => 'checkbox',
    '#title' => t('Disable content drafts'),
    '#description' => t('Prevent draft content from being sent to Quant.'),
    '#default_value' => variable_get('disable_content_drafts', TRUE),
  );

  $form['quant_rewrite_relative'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable relative paths'),
    '#description' => t('Ensure all assets are rewritten as relative paths.'),
    '#default_value' => $config->get('quant_rewrite_relative'),
  );

  $form['quant_proxy_override'] = array(
    '#type' => 'checkbox',
    '#title' => t('Override existing proxies'),
    '#description' => t('Overrides proxies created via the dashboard.'),
    '#default_value' => $config->get('quant_proxy_override'),
  );

  $form['quant_verbose_logging'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable verbose logging'),
    '#description' => t('Adds more information to logging outputs for each API call.'),
    '#default_value' => $config->get('quant_verbose_logging'),
  );

  $form['quant_verify_ssl'] = array(
    '#type' => 'checkbox',
    '#title' => t('Verify SSL'),
    '#description' => t('Optionally disable SSL verification when making internal requests for content to seed. This can be useful if your server is using a self-signed certificate.'),
    '#default_value' => $config->get('quant_verify_ssl'),
  );

  $form['quant_base_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Webserver URL'),
    '#description' => t('Provide the FQDN that internal requests may route to. e.g: http://localhost, http://nginx:8080, http:127.0.0.1. <a href="#">More info</a>'),
    '#default_value' => $config->get('quant_base_url'),
  );

  $form['quant_hostname'] = array(
    '#type' => 'textfield',
    '#title' => t('Hostname'),
    '#description' => t('Optionally provide the expected hostname for content served via quant, this ensures that absolute links in content point to the correct domain. This ensures absolute links in content point to the correct domain. e.g: www.example.com <a href="#">More info</a>'),
    '#default_value' => $config->get('quant_hostname'),
  );

  $form['#validate'][] = 'quant_form_quant_config_validate';

  return system_settings_form($form);
}

/**
 * Validate the configuration form to ensure that the settings are valid.
 */
function quant_form_quant_config_validate($form = array(), &$form_state = array()) {
  $base = quant_get_base_url();
  $options = array(
    'headers' => array(
      'Host' => config_get('quant.settings', 'quant_hostname'),
      'User-Agent' => 'Quant (+http://quantcdn.io)',
    ),
  );

  if (empty($form_state['quant_verify_ssl'])) {
    $options['context'] = stream_context_create(array(
      'ssl' => array(
        'verify_peer' => FALSE,
        'verify_peer_name' => FALSE,
      ),
    ));
  }

  $url = "$base/_quant/validate";
  $options['headers']['quant-token'] = quant_token_create($url);
  $response = backdrop_http_request($url, $options);

  $data = @json_decode($response->data, TRUE);
  if (json_last_error() != JSON_ERROR_NONE) {
    backdrop_set_message(t("Unable to connect to local webserver."), "error");
    return;
  }

  if (empty($data['message']) || $data['message'] != 'quant success') {
    backdrop_set_message(t("Unable to connect to local webserver."), "error");
    return;
  }

  backdrop_set_message(t("Quant configured correctly."));
}

/**
 * Form constructor for the seed form.
 *
 * @ingroup forms
 */
function quant_seed_settings() {
  $config = config('quant.settings');
  $form = array();
  $form['#config'] = 'quant.settings';
  _quant_get_seed_warnings($form);

  $form['quant_seed_entity_node'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export nodes'),
    '#default_value' => $config->get('quant_seed_entity_node'),
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
    '#default_value' => $config->get('quant_seed_entity_node_bundles'),
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
    '#default_value' => $config->get('quant_seed_entity_taxonomy'),
  );

  $form['quant_seed_theme_assets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export theme assets'),
    '#default_value' => $config->get('quant_seed_theme_assets'),
  );

  $form['quant_seed_views'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export Views'),
    '#default_value' => $config->get('quant_seed_views'),
  );

  // @TODO - Should we support user pages?
  $form['entity_users'] = array();

  // @TODO - add media entity support.
  $form['entity_media'] = array();

  $form['quant_custom_routes_enabled'] = [
    '#type' => 'checkbox',
    '#title' => t('Custom routes'),
    '#description' => t('Exports custom list of routes.'),
    '#default_value' => $config->get('quant_custom_routes_enabled'),
  ];

  $form['quant_custom_routes'] = [
    '#type' => 'textarea',
    '#title' => t('Routes'),
    '#description' => t('Add routes to export, each on a new line. Routes must not include domain and start with a slash, e.g: /about-us'),
    '#states' => [
      'visible' => [
        ':input[name="quant_custom_routes_enabled"]' => ['checked' => TRUE],
      ],
    ],
    '#default_value' => $config->get('quant_custom_routes'),
  ];

  $form['quant_robots'] = [
    '#type' => 'checkbox',
    '#title' => t('Export robots.txt'),
    '#default_value' => $config->get('quant_robots'),
  ];

  $form['trigger_quant_seed'] = array(
    '#type' => 'checkbox',
    '#title' => t('Trigger the batch'),
    '#description' => t('<strong>Note:</strong> This will attempt to trigger the seed from the UI, depending on the size of your site and PHP configuration this may not work.'),
    '#weight' => 998,
  );

  $form = system_settings_form($form);

  $form['actions']['#weight'] = 998;
  $form['#submit'][] = '_quant_queue_batch';

  $form['save_and_queue'] = array(
    '#type' => 'submit',
    '#value' => 'Save and Queue',
    '#submit' => array('system_settings_form_submit', '_quant_seed_prepare'),
    '#weight' => 999,
  );

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

/**
 * Quant token settings form.
 */
function quant_token_settings() {
  $config = config('quant.settings');

  $form = array();
  $form['#config'] = 'quant.settings';

  $form['quant_token_disable'] = array(
    '#type' => 'checkbox',
    '#title' => t('Disable token verification'),
    '#description' => t('Not recommended for production environments, this disables token verification.'),
    '#default_value' => $config->get('quant_token_disable'),
  );


  $form['quant_token_timeout'] = array(
    '#type' => 'textfield',
    '#title' => t('Token timeout'),
    '#description' => t('A string compatible with <a href="https://www.php.net/manual/en/function.strtotime.php">PHPs baseTimestamp</a> parameter'),
    '#default_value' => $config->get('quant_token_timeout'),
  );

  $form['quant_token_strict'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable strict token checks'),
    '#description' => t('This enforces strict token checking during seeds'),
    '#default_value' => $config->get('quant_token_strict'),
  );

  $form['quant_token_secret_regenerate'] = array(
    '#type' => 'checkbox',
    '#title' => t('Regerenate signing secret'),
    '#description' => t('This will regenerate the internal secret value Quant uses to sign internal requests'),
  );

  $form = system_settings_form($form);
  $form['#submit'][] = 'quant_token_regenerate';
  return $form;
}

/**
 * Regenerate quant signing secret.
 */
function quant_token_regenerate($form, &$form_state) {
  if ($form_state['values']['quant_token_secret_regenerate']) {
    config_set('quant.settings', 'quant_token_secret', bin2hex(random_bytes(32)));
  }
}

/**
 * Show a success message to validate configuration.
 */
function quant_validate() {
  // @todo: backdrop_json_output appears to return markup here.
  backdrop_add_http_header('Content-Type', 'application/json');
  echo json_encode(["message" => "quant success"]);
  exit(0);
}
