<?php

/**
 * @file
 * Seed content with drush.
 */

/**
 * Implements hook_drush_command().
 */
function quant_drush_command() {

  $items['quant-seed'] = array(
    'callback' => 'drush_quant_seed',
    'description' => 'Seed nodes',
    'arguments' => array(),
    'options' => array(),
    'aliases' => array('qs'),
  );

  return $items;
}

/**
 *
 */
function drush_quant_seed() {
  drush_log('Quant: Seed starting!', 'ok');

  $token = variable_get('quant_api_token');
  $customer = variable_get('quant_api_customer');
  $project = variable_get('quant_api_project');

  if (empty($token)) {
    drush_log('Quant API token is not configured', 'error');
    return FALSE;
  }
  if (empty($customer)) {
    drush_log('Quant API customer is not configured', 'error');
    return FALSE;
  }

  if (empty($project)) {
    drush_log('Quant API project is not configured', 'error');
  }

  drush_log("Seeding for $customer with $token", 'ok');

  _quant_seed_prepare();

  $batch =& batch_get();
  $batch['progressive'] = FALSE;

  drush_backend_batch_process();
}
