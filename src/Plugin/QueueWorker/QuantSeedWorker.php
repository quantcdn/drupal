<?php

namespace Drupal\quant\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\quant\Plugin\QueueItem\QuantQueueItemInterface;

/**
 * The Quant seed worker.
 *
 * @QueueWorker(
 *   id = "quant_seed_worker",
 *   title = @Translation("Quant Seed"),
 *   cron = {"time" = 60}
 * )
 */
class QuantSeedWorker extends QueueWorkerBase {

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    if (is_a($item, QuantQueueItemInterface::class)) {
      \Drupal::logger('quant_seed')->notice($item->log());
      return $item->send();
    }
  }

}
