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

  $items['quant-log-export'] = array(
    'callback' => 'drush_quant_log_export',
    'description' => 'Export the seed logs to a file',
    'arguments' => array(),
    'options' => array(
      'dir' => 'The file location to write the export to.',
    ),
    'aliases' => array('qle'),
  );

  $items['quant-log-import'] = array(
    'callback' => 'drush_quant_log_import',
    'description' => 'Import a seed log',
    'arguments' => array(),
    'options' => array(
      'file' => 'The file to import',
      'batch-size' => 'The batch size',
    ),
    'aliases' => array('qli'),
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

  $site = drush_get_option('uri');
  if (!empty($site)) {
    $cmd .= " --uri=$site";
  }

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
 * Callback to export the seed log data.
 */
function drush_quant_log_export() {
  drush_print('Preparing to export log entries.');
  $dir = drush_get_option('dir');
  if (empty($dir)) {
    $dir = file_directory_temp();
  }
  $loc = "$dir/quant-seed-log-" . date('Y-m-d') . '.csv';

  if (!file_exists($loc)) {
    // Make sure the file is ready for writing.
    touch($loc);
  }

  $file = fopen($loc, "a");

  if ($file === FALSE) {
    drush_log("Unable to open [$loc].");
    return FALSE;
  }

  $query = db_select('quant_log', 'ql');
  $query->fields('ql');
  $results = $query->execute();

  // Add the headers to the CSV.
  $schema = drupal_get_schema('quant_log');
  fwrite($file, implode(',', array_keys($schema['fields'])) . PHP_EOL);

  while ($row = $results->fetchAssoc()) {
    fwrite($file, implode(',', array_values($row)) . PHP_EOL);
  }

  drush_print("Successfully exported log entries to [$loc]");
}

function drush_quant_log_import() {
  drush_print('Preparing to import log entries.');
  $file = drush_get_option('file');
  $size = drush_get_option('batch-size') ?: 100;

  if (empty($file) || !file_exists($file)) {
    drush_print("Unable to open $file");
    return 1;
  }

  $file = fopen($file, "r");
  if (!$file) {
    drush_print("Unable to open $file");
    return 1;
  }

  $i = 0;
  $headers = [];

  $values = [];

  while ($line = fgets($file)) {
    $i++;
    $csv = str_getcsv($line);
    if ($i === 1) {
      // Skip the headers.
      $headers = $csv;
      continue;
    }
    $values[] = array_combine($headers, $csv);
  }

  $chunks = array_chunk($values, $size);
  $batch = [
    'title' => t('Quant seed log import'),
    'operations' => array(),
    'init_message' => t('Starting to import the log messages'),
    'progress_message' => t('Processed @current of @total'),
    'error_message' => t('An error occurred during processing'),
    'finished' => 'drush_quant_import_callback',
  ];

  foreach ($chunks as $chunk) {
    $batch['operations'][] = array(
      'drush_quant_process_log_import',
      array($chunk),
    );
  }
  batch_set($batch);
  drush_backend_batch_process();
}

function drush_quant_import_callback() {
  drush_print('Finished importing all records');
}

function drush_quant_process_log_import($chunk) {
  $query = db_insert('quant_log')
    ->fields(array_keys($chunk[0]));

  foreach ($chunk as $row) {
    $query->values($row);
  }

  $result = $query->execute();

  drush_print("Successfully created [$result] records");
}

/**
 * Validate Quant configuration prior to drush operation.
 */
function _validate_quant_config() {
  $token = variable_get('quant_api_token');
  // @todo Switch code from $customer to $organization.
  $customer = variable_get('quant_api_customer');
  $project = variable_get('quant_api_project');

  if (empty($token)) {
    drush_log('Quant API token is not configured', 'error');
    return FALSE;
  }
  if (empty($customer)) {
    drush_log('Quant API organization is not configured', 'error');
    return FALSE;
  }

  if (empty($project)) {
    drush_log('Quant API project is not configured', 'error');
  }

  drush_log("Quant: Running Quant operation for $customer: $project", 'ok');
}