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
    '#title' => t('API Endpoint'),
    '#description' => t('The FQDN for the API endpoint'),
    '#default_value' => variable_get('quant_api_endpoint', QUANT_ENDPOINT_DEFAULT),
  );

  $form['quant_api_token'] = array(
    '#type' => 'textfield',
    '#title' => t('API Token'),
    '#decription' => t('The API token'),
  );

  $form['#validate'][] = 'quant_api_settings_validate';

  return system_settings_form($form);
}
