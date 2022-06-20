<?php

namespace Drupal\quant_tome\Commands;

use Drush\Commands\DrushCommands;
use Drupal\quant_tome\QuantTomeBatch;
use Drupal\quant\Commands\QuantDrushCommands;

/**
 * Contains the quant:tome:deploy command.
 */
class QuantTomeCommands extends DrushCommands {

  /**
   * The batch builder.
   *
   * @var \Drupal\quant_tome\QuantTomeBatch
   */
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
  public function deploy(array $options = ['threads' => 5]) {
    $this->io()->writeln('Preparing Tome output for Quant...');

    if (!$this->batch->checkConfig()) {
      $this->io()->error('Cannot connect to the QuantAPI, please check configuration.');
      return 1;
    }
    if (!$this->batch->checkBuild()) {
      $this->io()->error('No static build available for deploy, please run "drush tome:static"');
      return 1;
    }

    $batch_builder = $this->batch->getBatch();
    batch_set($batch_builder->toArray());

    $result = drush_backend_batch_process();

    if (!empty($result['object'][0]['errors'])) {
      $this->io()->error('Deploy failed! Consult the error log for more information');
      return 1;
    }

    // Process the queue after the batch has collected it.
    $quant_drush = new QuantDrushCommands();
    $quant_drush->message(['threads' => $options['threads']]);
  }

}
