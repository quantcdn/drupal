<?php

/**
 * @file
 * Seed content with drush.
 */

/**
 * Implements hook_drush_command().
 */
function quant_drush_command() {

  $items['quant-queue-info'] = array(
    'callback' => 'drush_quant_info',
    'description' => 'Return current queue count.',
    'arguments' => array(),
    'options' => array(),
    'aliases' => array('qi'),
  );

  $items['quant-seed-queue'] = array(
    'callback' => 'drush_quant_seed',
    'description' => 'Populate the queue',
    'arguments' => array(),
    'options' => array(),
    'aliases' => array('qs'),
  );

  $items['quant-run-queue'] = array(
    'callback' => 'drush_quant_process_queue',
    'description' => 'Process the queue, push content to Quant',
    'arguments' => array(),
    'options' => array(
      'threads' => 'Number of concurrent threads. Default is 5, increase to improve seed performance.',
    ),
    'aliases' => array('qr'),
  );

  $items['quant-clear-queue'] = array(
    'callback' => 'drush_quant_clear_queue',
    'description' => 'Reset the queue',
    'arguments' => array(),
    'options' => array(),
    'aliases' => array('qc'),
  );

  return $items;
}

/**
 * Callback to return current queue count.
 */
function drush_quant_info() {
  $queue = quant_get_queue();
  drush_log('Quant: ' . $queue->numberOfItems() . ' items in the queue.', 'ok');
}

/**
 * Callback function to run a threaded seed process.
 */
function drush_quant_process_queue($threads=5) {
  static $procs;
  $cmd = 'drush queue-run quant_seed';
  _validate_quant_config();

  if (drush_get_option('threads')) {
    $threads = drush_get_option('threads');
  }

  if (empty($procs)) {
    $procs = array();
  }

  if (!intval($threads)) {
    drush_log('Invalid thread specification (must be an integer).', 'error');
  }

  drush_log("Quant: Forking seed worker with $threads threads.", 'ok');

  for ($i = 0; $i < $threads; $i++) {
    $procs[] = proc_open($cmd, [], $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
  }

  foreach ($procs as $proc) {
    $status = proc_get_status($proc);
    while ($status['running']) {
      $status = proc_get_status($proc);
    }
  }

  drush_log('Seeding complete.');

}

/**
 * Callback function to reset the queue.
 */
function drush_quant_clear_queue() {
  $queue = quant_get_queue();
  $queue->deleteQueue();
  drush_log('Quant: Queue has been cleared.', 'ok');
}

/**
 * Callback function to populate the Quant queue via drush.
 */
function drush_quant_seed() {
  _validate_quant_config();
  drush_log('Quant: Populating seed queue.', 'ok');
  _quant_seed_prepare();
}

/**
 * Validate Quant configuration prior to drush operation.
 */
function _validate_quant_config() {
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

  drush_log("Quant: Running Quant operation for $customer: $project", 'ok');
}
