<?php

namespace Drupal\quant_tome\Commands;

use Drush\Commands\DrushCommands;
use Drupal\quant_tome\QuantTomeBatch;

/**
 * Contains the quant:tome:deploy command.
 */
class QuantTomeCommands extends DrushCommands {

  protected $batch;

  /**
   * QuantTomeCommands constructor.
   *
   * @param \Drupal\quant_tome\QuantTomeBatch;
   *   The batch service.
   */
  public function __construct(QuantTomeBatch $batch) {
    $this->batch = $batch;
  }

  /**
   * Deploy a tome static build to Quant.
   *
   * @command quant:tome:deploy
   */
  public function deploy(array $options = []) {
    if (!$this->batch->checkConfig()) {
      $this->io()->error('Cannot connect to the QuantAPI, please check configuration.');
      return 1;
    }
    if (!$this->batch->checkBuild()) {
      $this->io()->error('No static build available for deploy, please run "drush tome:export.');
      return 1;
    }
    $batch_builder = $this->batch->getBatch();
    batch_set($batch_builder->toArray());
    $result = drush_backend_batch_process();
  }

}
