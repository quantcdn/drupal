<?php

/**
 * @file
 * Admin pages and operations for Quant.
 */

/**
 * Form constructor for the configuraton form.
 *
 * @ingroup forms
 */
function quant_config() {
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

  $form['quant_storage_location'] = array(
    '#type' => 'textfield',
    '#title' => t('Storage path'),
    '#description' => t('Location on disk to store static assets.'),
    '#default_value' => variable_get('quant_storage_location'),
  );

  return system_settings_form($form);
}

/**
 * Form contstructor for the seed form.
 *
 * @ingroup forms
 */
function quant_seed() {
  $form = array();

  _quant_get_seed_warnings($form);

  $form['quant_seed_entity_node'] = array(
    '#type' => 'checkbox',
    '#title' => t('Export nodes'),
    '#default_value' => variable_get('quant_seed_entity_node', FALSE),
  );

  $form['quant_seed_theme_asset'] = array(
    '#type' => 'checkbox',
    '#title' => t('Theme assets'),
    '#default_value' => variable_get('quant_seed_theme_asset', FALSE),
  );

  $form['entity_users'] = array();

  $form['entity_media'] = array();

  $form = system_settings_form($form);

  $form['#submit'][] = '_quant_seed_prepare';

  return $form;
}
