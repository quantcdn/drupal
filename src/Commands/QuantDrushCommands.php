<?php

namespace Drupal\quant\Commands;

use Drush\Commands\DrushCommands;

/**
 * A drush command file.
 *
 * @package Drupal\quant\Commands
 */
class QuantDrushCommands extends DrushCommands {

  /**
   * Drush command that executes the Quant queue.
   *
   * @command quant:run-queue
   * @aliases quant-queue-run
   * @option threads
   *   Number of threads to use (default 5)
   * @usage quant:run-queue --threads=5
   */
  public function message($options = ['threads' => 5]) {
    $this->output()->writeln("Forking seed worker.");
    for ($i = 0; $i < $options['threads']; $i++) {
      $cmd = 'drush queue:run quant_seed_worker';
      $process = proc_open($cmd, [], $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
    }
  }

}
