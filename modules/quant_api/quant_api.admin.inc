<?php

/**
 * @file
 * Admin pages and operations for Quant API.
 */

/**
 * Form constructor for the settings form.
 *
 * @ingroup forms
 */
function quant_api_settings() {
  if (!variable_get('quant_api_ssl_verify', TRUE)) {
    $form['warning'] = array(
      '#type' => 'markup',
      '#prefix' => '<div class="messages error">',
      '#suffix' => '</div>',
      '#markup' => '<strong>DANGER ZONE:</strong> SSL verification is disabled for Quant API connections. It is <strong>highly recommended</strong> that you update your server configuration to handle SSL rather than disabling SSL verification.'
    );
  }

  $css_class = 'status';
  if (empty(variable_get('quant_api_customer')) || empty(variable_get('quant_api_project')) || empty(variable_get('quant_api_token'))) {
    $css_class = 'warning';
  }
  $form['help'] = array(
    '#type' => 'markup',
    '#prefix' => '<div class="messages ' . $css_class . '">',
    '#suffix' => '</div>',
    '#markup' => 'You can find your API information in your Quant dashboard. In the dashboard, first choose your organization and project. Then, go to the <code>Integrations</code> page and the API information is shown in the <code>Integration details</code> section.'
  );

  $form['quant_api_endpoint'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('API Endpoint'),
    '#description' => t('The fully-qualified domain name for the API endpoint, e.g. <code>@endpoint</code>', array('@endpoint' => QUANT_API_ENDPOINT_DEFAULT)),
    '#default_value' => variable_get('quant_api_endpoint', QUANT_API_ENDPOINT_DEFAULT),
  );

  // @todo Switch from 'quant_api_customer' to 'quant_api_organization'.
  $form['quant_api_customer'] = array(
    '#type' => 'textfield',
    '#title' => t('API Organization'),
    '#description' => t('The API organization. This is shown on the <code>Integrations</code> page and with the account information.'),
    '#default_value' => variable_get('quant_api_customer'),
  );

  $form['quant_api_project'] = array(
    '#type' => 'textfield',
    '#title' => t('API Project'),
    '#description' => t('The API project. This is the <code>API name</code> shown on the <code>Projects</code> and <code>Integrations</code> pages.'),
    '#default_value' => variable_get('quant_api_project'),
  );

  $form['quant_api_token'] = array(
    '#type' => 'password',
    '#title' => t('API Token'),
    '#description' => t('The API token. Use the clipboard icon in the dashboard to copy the token. Be careful with this information. It should be treated like any system password.'),
  );

  $form['quant_api_ssl_verify'] = array(
    '#type' => 'checkbox',
    '#title' => t('Verify SSL'),
    '#description' => t('You can optionally disable SSL verification for all Quant API requests. This is <strong>not recommended</strong>, but may be necessary in some configurations.'),
    '#default_value' => variable_get('quant_api_ssl_verify', TRUE),
  );

  $form['#validate'][] = 'quant_api_settings_validate';

  return system_settings_form($form);
}

/**
 * Implements hook_validate().
 */
function quant_api_settings_validate($form, &$form_state) {
  if (empty($form_state['values']['quant_api_token'])) {
    $form_state['values']['quant_api_token'] = variable_get('quant_api_token');
  }

  $url = $form_state['values']['quant_api_endpoint'];
  $url = rtrim($url, '/');

  // Sanitize our API endpoint.
  $form_state['values']['quant_api_endpoint'] = $url;
  $api = $url . '/v1';

  // Try a ping :).
  // @todo Switch from 'quant-customer' to 'quant-organization'.
  $options = array(
    'headers' => array(
      'quant-customer' => $form_state['values']['quant_api_customer'],
      'quant-token' => $form_state['values']['quant_api_token'],
      'quant-project' => $form_state['values']['quant_api_project'],
    ),
  );

  if (!$form_state['values']['quant_api_ssl_verify']) {
    $options['context'] = stream_context_create(array(
      'ssl' => array(
        'verify_peer' => FALSE,
        'verify_peer_name' => FALSE,
      )
    ));
  }

  $response = drupal_http_request("$api/ping", $options);

  if (!property_exists($response, 'data')) {
    form_set_error('quant_api_endpoint', 'QuantAPI error: Unable to connect to the Quant API please check the endpoint on the <code>Integrations</code> page in the Quant dashboard.');
    return;
  }

  $body = json_decode($response->data);

  if (!empty($response->error)) {
    $message = !empty($body->errorMsg) ? $body->errorMsg : 'Unauthorized';
    form_set_error('quant_api_token', 'QuantAPI error: Unable to connect to the Quant API. Check the API organization, project, and token details on the <code>Integrations</code> page in the Quant dashboard.');
    drupal_set_message('QuantAPI error: <code>' . $message . '</code>', 'error');
  }
  else {
    drupal_set_message('QuantAPI status: Successfully connected to Quant project <code>' . $body->project . '</code> for organization <code>' . $form_state['values']['quant_api_customer'] . '</code>.');
  }
}
