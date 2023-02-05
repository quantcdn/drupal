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
    $form['markup'] = array(
      '#type' => 'markup',
      '#prefix' => '<div class="messages error">',
      '#suffix' => '</div>',
      '#markup' => '<b>DANGER ZONE</b> SSL verification is disabled for Quant API connections, it is recommended that you address server configuration rather than disabling SSL verification.'
    );
  }

  $form['quant_api_endpoint'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('API Endpoint'),
    '#description' => t('The FQDN for the API endpoint'),
    '#default_value' => variable_get('quant_api_endpoint', QUANT_API_ENDPOINT_DEFAULT),
  );

  $form['quant_api_token'] = array(
    '#type' => 'password',
    '#title' => t('API Token'),
    '#decription' => t('The API token'),
  );

  $form['quant_api_customer'] = array(
    '#type' => 'textfield',
    '#title' => t('API Customer'),
    '#description' => t('The API customer'),
    '#default_value' => variable_get('quant_api_customer'),
  );

  $form['quant_api_project'] = array(
    '#type' => 'textfield',
    '#title' => t('API Project'),
    '#description' => t('The API project'),
    '#default_value' => variable_get('quant_api_project'),
  );

  $form['quant_api_ssl_verify'] = array(
    '#type' => 'checkbox',
    '#title' => t('Verify SSL'),
    '#description' => t('You can optionally disable SSL verification for all Quant API requests, this is <b>not recommended</b> but may be necessary in some configurations.'),
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
    form_set_error('quant_api_endpoint', 'Unable to connect to the Quant API please check the endpoint.');
    return;
  }

  $body = json_decode($response->data);

  if (!empty($response->error)) {
    $message = !empty($body->errorMsg) ? $body->errorMsg : 'Unauthorized';
    form_set_error('quant_api_token', 'Unable to connect to the Quant API please check the API token and customer match.');
    drupal_set_message('QuantAPI error: ' . $message, 'error');
  }
  else {
    drupal_set_message('QuantAPI: Successfully connected to ' . $body->project);
  }
}
