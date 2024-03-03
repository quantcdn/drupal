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

  drupal_set_title(t('Quant Configuration'));

  // Link off to the most relevant documentation.
  $form['docs'] = array(
    '#type' => 'markup',
    '#prefix' => '<div class="messages status">',
    '#suffix' => '</div>',
    '#markup' => 'Learn more about the Quant Drupal module in the <a href="https://docs.quantcdn.io/docs/integrations/drupal">Quant Drupal integration documentation</a>.'
  );

  $form['quant_enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Track content changes'),
    '#description' => t('Automatically push content changes to Quant (recommended).'),
    '#default_value' => variable_get('quant_enabled', TRUE),
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
    '#default_value' => variable_get('quant_enabled_nodes', TRUE),
  ];

  $form['tracking_fieldset']['quant_enabled_taxonomy'] = [
    '#type' => 'checkbox',
    '#title' => t('Taxonomy Terms'),
    '#default_value' => variable_get('quant_enabled_taxonomy', TRUE),
  ];

  if (module_exists('views')) {
    $form['tracking_fieldset']['quant_enabled_views'] = [
      '#type' => 'checkbox',
      '#title' => t('Views'),
      '#default_value' => variable_get('quant_enabled_views', TRUE),
    ];
  }

  $form['follow_links_fieldset'] = [
    '#type' => 'fieldset',
    '#title' => t('Follow links'),
    '#description' => t('Automatically add certain links to the queue (e.g Views pagination)'),
    '#attached' => array(
      'library' => array(
        array('system', 'drupal.collapse'),
      ),
    ),
    '#attributes' => array(
      'class' => array('collapsible', 'collapsed')
    ),
  ];

  $form['follow_links_fieldset']['quant_xpath_selectors'] = [
    '#type' => 'textarea',
    '#title' => t('Links to follow'),
    '#default_value' => variable_get('quant_xpath_selectors', "//a[contains(@href,\"page=\") and contains(text(), \"next\")]\r\n//a[starts-with(@href, \"/\") and contains(text(), \"first\")]"),
    '#description' => t('Provide one xpath per line for anchor links to queue when detected.'),
  ];

  $form['disable_content_drafts'] = array(
    '#type' => 'checkbox',
    '#title' => t('Disable content drafts'),
    '#description' => t('Prevent draft content from being sent to Quant.'),
    '#default_value' => variable_get('disable_content_drafts', TRUE),
  );

  $form['quant_seed_check_log'] = array(
    '#type' => 'checkbox',
    '#title' => t('Check logs before seeding'),
    '#description' => t('Check the logs to see if content has already been seeded and, if so, skip seeding that content. If you need to reseed everything, disable this option or truncate the `quant_log` table. Enabling this option can speed up the seeding process.'),
    '#default_value' => variable_get('quant_seed_check_log', FALSE),
  );

  $form['quant_rewrite_relative'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable relative paths'),
    '#description' => t('Ensure all assets are rewritten as relative paths.'),
    '#default_value' => variable_get('quant_rewrite_relative', TRUE),
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

  $form['quant_verify_ssl'] = array(
    '#type' => 'checkbox',
    '#title' => t('Verify SSL'),
    '#description' => t('Optionally, disable SSL verification when making internal requests for content to seed. This can be useful if your server is using a self-signed certificate.'),
    '#default_value' => variable_get('quant_verify_ssl', TRUE),
  );

  $form['quant_alternate_file_handler'] = array(
    '#type' => 'checkbox',
    '#title' => t('Alternate file handler'),
    '#description' => t('Retrieves media assets via HTTP request. Adds support for custom stream wrappers, or any storage backend other than local files.'),
    '#default_value' => variable_get('quant_alternate_file_handler', FALSE),
  );

  $form['quant_seed_get'] = array(
    '#type' => 'checkbox',
    '#title' => t('Use GET for seeding'),
    '#description' => t("By default, seeding uses POST to bypass Drupal's caching and ensure the freshest content. For faster seeding that leverages Drupal's caching, you can enable this option and it will use GET rather than POST. This can be helpful during initial seeding for large sites."),
    '#default_value' => variable_get('quant_seed_get', TRUE),
  );

  $form['quant_base_url'] = array(
    '#type' => 'textfield',
    '#title' => t('Webserver URL'),
    '#description' => t('Provide the fully-qualified domain name that internal requests may route to, e.g. <code>http://localhost</code>, <code>http://nginx:8080</code>, <code>http://127.0.0.1</code>'),
    '#default_value' => variable_get('quant_base_url', 'http://localhost'),
  );

  $form['quant_hostname'] = array(
    '#type' => 'textfield',
    '#title' => t('Hostname'),
    '#description' => t('Optionally, provide the expected hostname for content served via Quant. This ensures absolute links in content point to the correct domain, e.g. <code>www.example.com</code>'),
    '#default_value' => variable_get('quant_hostname', ''),
  );

  $form['quant_allowed_external_domains'] = [
    '#type' => 'textarea',
    '#title' => t('Allowed external domains'),
    '#description' => t('Optionally provide external domains embedded static assets should be retrieved from.'),
    '#default_value' => variable_get('quant_allowed_external_domains', FALSE),
  ];

  $form['#validate'][] = 'quant_form_quant_config_validate';

  return system_settings_form($form);
}

/**
 * Validate the configuration form to ensure that the settings are valid.
 */
function quant_form_quant_config_validate($form = array(), &$form_state = array()) {
  global $base_url;

  // Check webserver URL is valid.
  $quant_base_url = trim($form_state['values']['quant_base_url']);
  if (empty($quant_base_url)) {
    // Use the base URL by default.
    $quant_base_url = $base_url;
    if (empty($quant_base_url)) {
      // Shouldn't happen, but check anyway.
      form_set_error('quant_base_url', 'Your Drupal base URL, @quant_base_url, is not configured correctly. Check your settings.php file.', array('@quant_base_url' => $quant_base_url));
      return;
    }
    else {
      drupal_set_message(t('Since the Webserver URL field has been left empty, the base URL in your site will be used instead: <code>@quant_base_url</code>', array('@quant_base_url' => $quant_base_url)));
    }
  }
  else {
    $error = 'Your Webserver URL is set incorrectly. It should be a fully-qualified domain name, e.g. <code>http://localhost</code>.';
    if (filter_var($quant_base_url, FILTER_VALIDATE_URL) === FALSE) {
      form_set_error('quant_base_url', $error);
      return;
    }

    // Basic check for stray characters. The final check is below when using it.
    $quant_base_url_scheme = parse_url($quant_base_url, PHP_URL_SCHEME);
    $quant_base_url_user = parse_url($quant_base_url, PHP_URL_USER);
    $quant_base_url_pass = parse_url($quant_base_url, PHP_URL_PASS);
    $quant_base_url_host = parse_url($quant_base_url, PHP_URL_HOST);
    $quant_base_url_port = parse_url($quant_base_url, PHP_URL_PORT);
    $quant_base_url_check = $quant_base_url_scheme . '://';
    if ($quant_base_url_user || $quant_base_url_pass) {
      $quant_base_url_check .= $quant_base_url_user . '@' . $quant_base_url_pass;
    }
    $quant_base_url_check .= $quant_base_url_host;
    if ($quant_base_url_port) {
      $quant_base_url_check .= ':' . $quant_base_url_port;
    }
    if ($quant_base_url_check != $quant_base_url) {
      form_set_error('quant_base_url', $error);
      return;
    }
  }

  // Check hostname is a valid domain.
  $quant_hostname = trim($form_state['values']['quant_hostname']);
  if (empty($quant_hostname)) {
    // Use the base URL by default.
    $quant_hostname = parse_url($quant_base_url, PHP_URL_HOST);
    if (empty($quant_hostname)) {
      // Shouldn't happen, but check anyway.
      form_set_error('quant_hostname', 'Your Drupal base URL, @quant_base_url, is not configured correctly. Check your settings.php file.', array('@quant_base_url' => $quant_base_url));
      return;
    }
    else {
      $form_state['values']['quant_hostname'] = $quant_hostname;
      drupal_set_message(t('Since the Hostname field has been left empty, the base URL in your site will be used instead: <code>@quant_hostname</code>', array('@quant_hostname' => $quant_hostname)));
    }
  }
  else {
    // Check it's a valid URL.
    $error = 'Your hostname is set incorrectly. It should be a valid domain name, e.g. <code>www.example.com</code>.';
    if (filter_var('http://' . $quant_hostname, FILTER_VALIDATE_URL) === FALSE) {
      form_set_error('quant_hostname', $error);
      return;
    }

    // Basic check for stray characters. The final check is below when using it.
    // Note that using dns_get_record or similar can be problematic.
    $quant_hostname_check = parse_url('http://' . $quant_hostname, PHP_URL_HOST);
    if ($quant_hostname_check != $quant_hostname) {
      form_set_error('quant_hostname', $error);
      return;
    }
  }

  $options = array(
    'headers' => array(
      'Host' => $quant_hostname,
      'User-Agent' => 'Quant (+http://quantcdn.io)',
    ),
  );

  if (empty($form_state['quant_verify_ssl'])) {
    $options['context'] = stream_context_create(array(
      'ssl' => array(
        'verify_peer' => FALSE,
        'verify_peer_name' => FALSE,
      )
    ));
  }

  $url = "$quant_base_url/_quant/validate";
  $options['headers']['quant-token'] = quant_token_create($url);
  $response = drupal_http_request($url, $options);

  $data = @json_decode($response->data, TRUE);
  // Most likely there is something wrong with the hostname.
  $error = 'Invalid Quant configuration. Check your Webserver URL and Hostname are set correctly, e.g. <code>http://localhost</code> and <code>www.example.com</code>.';
  if (json_last_error() != JSON_ERROR_NONE) {
    form_set_error('quant_hostname', $error);
    return;
  }

  if (empty($data['message']) || $data['message'] != 'quant success') {
    form_set_error('quant_hostname', $error);
    return;
  }

  drupal_set_message(t('Quant has been configured correctly.'));
}

/**
 * Form constructor for the seed form.
 *
 * @ingroup forms
 */
function quant_seed_settings() {

  drupal_set_title(t('Quant Seed'));

  $form = array();
  _quant_get_seed_warnings($form);

  // Link off to the most relevant documentation.
  $form['docs'] = array(
    '#type' => 'markup',
    '#prefix' => '<div class="messages status">',
    '#suffix' => '</div>',
    '#markup' => 'Learn more about this feature in the <a href="https://docs.quantcdn.io/docs/integrations/drupal/seed">Quant seed documentation</a>.'
  );

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
    '#description' => t('Optionally, restrict to these content types.'),
    '#default_value' => variable_get('quant_seed_entity_node_bundles', []),
    '#options' => $options,
    '#states' => array(
      'visible' => array(
        ':input[name="quant_seed_entity_node"]' => array('checked' => TRUE),
      ),
    ),
  );

  $form['quant_seed_menu_paths_enabled'] = [
    '#type' => 'checkbox',
    '#title' => t('Menu paths'),
    '#description' => t('Exports routes as defined in a menu structure.'),
    '#default_value' => variable_get('quant_seed_menu_paths_enabled', FALSE),
  ];

  // Filter by menu.
  $menus = menu_get_menus();

  $options = array();
  foreach ($menus as $menu_name => $menu) {
    $options[$menu_name] = $menu;
  }

  $form['quant_seed_menu_paths_bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Enabled menus for paths'),
    '#description' => t('Optionally, restrict to these menus.'),
    '#default_value' => variable_get('quant_seed_menu_paths_bundles', []),
    '#options' => $options,
    '#states' => array(
      'visible' => array(
        ':input[name="quant_seed_menu_paths_enabled"]' => array('checked' => TRUE),
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
    '#title' => t('Export default theme assets'),
    '#default_value' => variable_get('quant_seed_theme_assets', FALSE),
  );

  $form['quant_seed_additional_theme_assets_enabled'] = [
    '#type' => 'checkbox',
    '#title' => t('Additional theme assets'),
    '#description' => t('Exports CSS/JS/static assets from non-default themes.'),
    '#default_value' => variable_get('quant_seed_additional_theme_assets_enabled', FALSE),
  ];

  $form['quant_seed_additional_theme_assets'] = array(
    '#type' => 'textarea',
    '#title' => t('Export additional theme assets'),
    '#default_value' => variable_get('quant_seed_additional_theme_assets', ''),
    '#description' => t('Provide additional theme machine names (separated by newline)'),
    '#states' => [
      'visible' => [
        ':input[name="quant_seed_additional_theme_assets_enabled"]' => ['checked' => TRUE],
      ],
    ],
  );

  if (module_exists('views')) {
    $form['quant_seed_views'] = array(
      '#type' => 'checkbox',
      '#title' => t('Export Views'),
      '#default_value' => variable_get('quant_seed_views', FALSE),
    );
  }

  if (module_exists('page_manager')) {
    $form['quant_seed_page_manager'] = array(
      '#type' => 'checkbox',
      '#title' => t('Export page manager pages'),
      '#default_value' => variable_get('quant_seed_page_manager', FALSE),
    );
  }

  // @TODO - Should we support user pages?
  $form['entity_users'] = array();

  // @TODO - add media entity support.
  $form['entity_media'] = array();

  $form['quant_custom_routes_enabled'] = [
    '#type' => 'checkbox',
    '#title' => t('Custom routes'),
    '#description' => t('Exports custom list of routes.'),
    '#default_value' => variable_get('quant_custom_routes_enabled', FALSE),
  ];

  $form['quant_custom_routes'] = [
    '#type' => 'textarea',
    '#title' => t('Routes'),
    '#description' => t('Add routes to export, each on a new line. Routes must not include domain and start with a slash, e.g. /about-us'),
    '#states' => [
      'visible' => [
        ':input[name="quant_custom_routes_enabled"]' => ['checked' => TRUE],
      ],
    ],
    '#default_value' => variable_get('quant_custom_routes', FALSE),
  ];

  // Only show if it exists.
  $file = $_SERVER['DOCUMENT_ROOT'] . '/robots.txt';
  if (file_exists($file)) {
    $form['quant_robots'] = [
      '#type' => 'checkbox',
      '#title' => t('Export robots.txt'),
      '#default_value' => variable_get('quant_robots', FALSE),
    ];
  }

  $form['trigger_quant_seed'] = array(
    '#type' => 'checkbox',
    '#title' => t('Trigger the batch'),
    '#description' => t('<strong>Note:</strong> This will attempt to trigger the seed from the UI. Depending on the size of your site and your PHP configuration, this may not work. To try it, enable the option and click the <code>Save and Queue</code> button.'),
    '#default_value' => FALSE,
    '#weight' => 998,
  );

  $form = system_settings_form($form);

  $form['actions']['submit']['#value'] = 'Save';
  $form['actions']['#weight'] = 998;
  $form['actions']['submit']['#validate'][] = 'quant_form_quant_seed_validate';

  $form['actions']['save_and_queue'] = array(
    '#type' => 'submit',
    '#value' => 'Save and Queue',
    '#submit' => array('system_settings_form_submit', '_quant_queue_batch'),
    '#weight' => 999,
  );

  $form['actions']['clear_queue'] = array(
    '#type' => 'submit',
    '#value' => 'Clear Queue',
    '#submit' => array('system_settings_form_submit', '_quant_queue_clear'),
    '#weight' => 9999,
  );

  return $form;
}

/**
 * Validate the seed form to ensure that the settings are valid.
 */
function quant_form_quant_seed_validate($form = array(), &$form_state = array()) {
  if (!empty($form_state['values']['trigger_quant_seed'])) {
    drupal_set_message(t('To trigger the batch in the UI, check the option and click <code>Save and Queue</code>.'), "warning");
  }
}

/**
 * Present items that are queued for Quant sending.
 */
function quant_queue_page() {

  drupal_set_title(t('Quant Queue'));

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
    $path = current($context);

    // Strip out base url.
    $base_url = quant_get_base_url();
    $path = str_replace($base_url, '', $path);

    // Show entity URL and alias.
    $type = ['quant_seed_node_batch' => 'node/', 'quant_seed_taxonomy_batch' => 'taxonomy/term/'];
    foreach ($type as $batch_op => $prefix) {
      if ($op == $batch_op) {
        $path = $prefix . $path;
        $alias = drupal_get_path_alias($path);
        $path = ($path == $alias) ? '/' . $path : '/' . $path . ' (/' . $alias . ')';
      }
    }

    // Show redirect info.
    if ($op == 'quant_redirect_request') {
      $destination = next($context);
      $status_code = next($context);
      $path = '/' . $path . ' => ' . $destination . ' [' . $status_code . ']';
    }

    $item['content'] = t('<b>Callback:</b> %op<br /><b>URL:</b> %arg', array(
      '%op' => $op,
      '%arg' => $path,
    ));
    unset($item['data']);
    $rows[] = $item;
  }

  // Link off to the most relevant documentation.
  $build['docs'] = array(
    '#type' => 'markup',
    '#prefix' => '<div class="messages status">',
    '#suffix' => '</div>',
    '#markup' => 'Learn more about this feature in the <a href="https://docs.quantcdn.io/docs/integrations/drupal/seed">Quant seed documentation</a>.'
  );

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

  drupal_set_title(t('Quant Token'));

  $form = array();

  // Link off to the most relevant documentation. Currently, there is no token-specific docs.
  $form['docs'] = array(
    '#type' => 'markup',
    '#prefix' => '<div class="messages status">',
    '#suffix' => '</div>',
    '#markup' => 'Learn more about the Quant Drupal module in the <a href="https://docs.quantcdn.io/docs/integrations/drupal">Quant Drupal integration documentation</a>.'
  );

  $form['quant_token_disable'] = array(
    '#type' => 'checkbox',
    '#title' => t('Disable token verification'),
    '#description' => t('Not recommended for production environments, this disables token verification.'),
    '#default_value' => variable_get('quant_token_disable', FALSE),
  );

  $form['quant_token_timeout'] = array(
    '#type' => 'textfield',
    '#title' => t('Token timeout'),
    '#description' => t('A string compatible with <a href="https://www.php.net/manual/en/datetime.formats.php">PHP datetime formats</a>, e.g. <code>+1 minute</code>'),
    '#default_value' => variable_get('quant_token_timeout', '+1 minute'),
  );

  $form['quant_token_strict'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable strict token checks'),
    '#description' => t('This enforces strict token checking during seeds'),
    '#default_value' => variable_get('quant_token_strict', true),
  );

  $form['quant_token_secret_regenerate'] = array(
    '#type' => 'checkbox',
    '#title' => t('Regenerate signing secret'),
    '#description' => t('This will regenerate the internal secret value Quant uses to sign internal requests'),
  );

  $form = system_settings_form($form);
  $form['#validate'][] = 'quant_form_quant_token_validate';
  $form['#submit'][] = 'quant_token_regenerate';
  return $form;
}

/**
 * Validate the token form to ensure that the settings are valid.
 */
function quant_form_quant_token_validate($form = array(), &$form_state = array()) {
  // The timeout must be a valid format, be in the future, and include a plus sign.
  $now = strtotime(date('Y-m-d'));
  $timeout = $form_state['values']['quant_token_timeout'];
  $time_check = strtotime($timeout);
  if (!empty($timeout) && ($time_check === FALSE || $time_check < $now || strpos($timeout, '+') === FALSE)) {
    form_set_error('quant_token_timeout', 'The <code>Token timeout</code> format is incorrect. See <a href="https://www.php.net/manual/en/datetime.formats.php">PHP datetime documentation</a> for valid formatting options. Example: <code>+1 minute</code>');
  }
}

/**
 * Regenerate Quant signing secret.
 */
function quant_token_regenerate($form, &$form_state) {
  if ($form_state['values']['quant_token_secret_regenerate']) {
    variable_set('quant_token_secret', bin2hex(random_bytes(32)));
  }
}

/**
 * Show a success message to validate configuration.
 */
function quant_validate() {
  $response = array("message" => "quant success");
  return drupal_json_output($response);
}
