<?php

namespace Drupal\quant_tome\Commands;

use Drush\Commands\DrushCommands;
use Drupal\quant\CliDomainContext;
use Drupal\quant\Commands\QuantDrushCommands;
use Drupal\quant_tome\QuantTomeBatch;

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
   * @param \Drupal\quant_tome\QuantTomeBatch $batch
   *   The batch service.
   */
  public function __construct(QuantTomeBatch $batch) {
    $this->batch = $batch;
  }

  /**
   * Deploy a Tome static build to Quant.
   *
   * @command quant:tome:deploy
   */
  public function deploy(array $options = ['threads' => 5]) {
    // Resolve the domain before checkConfig() reads the API settings,
    // otherwise the connection test and the whole deploy target the base
    // project rather than this domain's.
    $domainId = CliDomainContext::initialize();

    $this->io()->writeln('Preparing Tome output for Quant...');

    if ($domainId) {
      $this->io()->writeln(sprintf('Active domain: %s. Target project: %s.', $domainId, CliDomainContext::getActiveProject()));
    }

    if (!$this->batch->checkConfig()) {
      $this->io()->error('Cannot connect to the Quant API. Please check the Quant configuration.');
      return 1;
    }
    if (!$this->batch->checkBuild()) {
      $this->io()->error('No Tome static build is available. Please run "drush tome:static".');
      return 1;
    }

    $batch_builder = $this->batch->getBatch();
    batch_set($batch_builder->toArray());

    $result = drush_backend_batch_process();

    if (!empty($result['object'][0]['errors'])) {
      $this->io()->error('Deploy failed. Please consult the error log for more information.');
      return 1;
    }

    // Process the queue after the batch has collected it.
    $quant_drush = new QuantDrushCommands();
    $quant_drush->message(['threads' => $options['threads']]);
  }

}
