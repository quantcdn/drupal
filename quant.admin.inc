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

  $form['quant_content_revisions'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable content revisions'),
    '#description' => t('Any content change will create a new revision in Quant.'),
    '#default_value' => variable_get('quant_content_revisions', FALSE),
  );

  $form['quant_asset_revisions'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enable asset revisions'),
    '#description' => t('Media revisions will be tracked when files/images/etc change.'),
    '#default_value' => variable_get('quant_asset_revisions', FALSE),
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

  quant_test(6183);

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

  $form['quant_seed_theme_assets'] = array(
    '#type' => 'checkbox',
    '#title' => t('Theme assets'),
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

  $form['quant_seed'] = array(
    '#type' => 'checkbox',
    '#title' => t('Trigger the batch'),
    '#description' => t('<strong>Note:</strong> This will attempt to trigger the seed from the UI, depending on the size of your site and PHP configuration this may not work.'),
  );

  $form = system_settings_form($form);

  $form['#submit'][] = '_quant_seed_prepare';

  return $form;
}
