<?php

namespace Drupal\quant\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\quant\CliDomainContext;
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
    if (!is_a($item, QuantQueueItemInterface::class)) {
      return NULL;
    }

    // Resolve the active domain before reading the target project. Workers
    // forked by quant:run-queue inherit --uri but never dispatch a
    // kernel.request, so the domain context is otherwise empty.
    CliDomainContext::initialize();

    if (!$this->targetsActiveProject($item)) {
      return NULL;
    }

    \Drupal::logger('quant_seed')->notice($item->log());
    return $item->send();
  }

  /**
   * Confirms the item belongs to the project this process publishes to.
   *
   * Publishing an item to the wrong project puts one site's content on
   * another's domain. On a shared Drupal instance serving many clients that
   * is a content leak, so a mismatch stops the send rather than risking it.
   *
   * @param \Drupal\quant\Plugin\QueueItem\QuantQueueItemInterface $item
   *   The queue item.
   *
   * @return bool
   *   TRUE when the item may be sent.
   */
  protected function targetsActiveProject(QuantQueueItemInterface $item) : bool {
    $target = $item->getTargetProject();

    // Items queued before the stamp existed carry no target. Send them, to
    // keep existing single-domain queues working across an update.
    if (empty($target)) {
      return TRUE;
    }

    $active = \Drupal::service('quant_api.client')->getProject();

    if ($target === $active) {
      return TRUE;
    }

    \Drupal::logger('quant_seed')->error('Skipped @item: queued for project @target but this worker publishes to @active. Run the queue with --uri set to the domain that owns @target.', [
      '@item' => $item->log(),
      '@target' => $target,
      '@active' => $active ?: 'none',
    ]);

    return FALSE;
  }

}
