<?php

namespace Drupal\quant\Plugin\QueueWorker;

use Drupal\Core\Queue\DelayedRequeueException;
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
   * Seconds to hold a mismatched item before it can be claimed again.
   */
  const REQUEUE_DELAY = 60;

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

    $this->assertTargetsActiveProject($item);

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
   * The item is put back rather than dropped. A worker returning normally has
   * its item deleted, so simply declining to send would consume work queued
   * for another domain and that content would never be published at all. The
   * queue is a single shared table, so whichever domain's worker claims first
   * would quietly eat the rest.
   *
   * @param \Drupal\quant\Plugin\QueueItem\QuantQueueItemInterface $item
   *   The queue item.
   *
   * @throws \Drupal\Core\Queue\DelayedRequeueException
   *   When the item belongs to a different project.
   */
  protected function assertTargetsActiveProject(QuantQueueItemInterface $item) : void {
    $target = $item->getTargetProject();

    // Items queued before the stamp existed carry no target. Send them, to
    // keep existing single-domain queues working across an update.
    if (empty($target)) {
      return;
    }

    $active = \Drupal::service('quant_api.client')->getProject();

    if ($target === $active) {
      return;
    }

    \Drupal::logger('quant_seed')->error('Requeued @item: queued for project @target but this worker publishes to @active. Run the queue with --uri set to the domain that owns @target.', [
      '@item' => $item->log(),
      '@target' => $target,
      '@active' => $active ?: 'none',
    ]);

    // Long enough that a single run cannot spin on the same item, short
    // enough that the correct worker picks it up on its next pass.
    throw new DelayedRequeueException(self::REQUEUE_DELAY);
  }

}
