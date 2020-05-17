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
  $form['quant_api_endpoint'] = array(
    '#type' => 'textfield',
    '#required' => TRUE,
    '#title' => t('API Endpoint'),
    '#description' => t('The FQDN for the API endpoint'),
    '#default_value' => variable_get('quant_api_endpoint', QUANT_ENDPOINT_DEFAULT),
  );

  $form['quant_api_token'] = array(
    '#type' => 'textfield',
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

  // Sanitise our API endpoint.
  $form_state['values']['quant_api_endpoint'] = $url;

  // Try a ping :).
  $options = array(
    'headers' => array(
      'quant-customer' => $form_state['values']['quant_api_customer'],
      'quant-token' => $form_state['values']['quant_api_token'],
      'quant-project' => $form_state['values']['quant_api_project'],
    ),
  );

  $response = drupal_http_request("$url/ping", $options);
  $body = json_decode($response->data);

  if (!empty($response->error)) {
    form_set_error('quant_api_token', 'Unable to connect to the QuantAPI please check the API token and customer match.');
    drupal_set_message('QuantAPI error: ' . $body->errorMsg, 'error');
  } else {
    drupal_set_message('QuantAPI: Successfully connected to ' . $body->project);
  }
}
