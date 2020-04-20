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
  drush_log('Quant: Seed starting!', 'notice');

  _quant_seed_prepare();

  $batch =& batch_get();
  $batch['progressive'] = FALSE;

  drush_log('  - total: ' . count($batch['operations']), 'notice');

  drush_backend_batch_process();
}
