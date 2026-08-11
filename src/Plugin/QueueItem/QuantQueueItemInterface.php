<?php

namespace Drupal\quant\Plugin\QueueItem;

/**
 * A standard definition for a queue item.
 *
 * @ingroup quant
 */
interface QuantQueueItemInterface {

  /**
   * Seed the item to Quant.
   */
  public function send();

  /**
   * Returns the Quant project this item was queued for.
   *
   * Recorded at enqueue time so the worker can confirm it is publishing to
   * the intended destination. Multi-domain sites resolve a different project
   * per domain, and the worker does not always boot in the domain that
   * created the item.
   *
   * @return string|null
   *   The project machine name, or NULL when the item carries no stamp.
   */
  public function getTargetProject() : ?string;

  /**
   * Describe the current item.
   *
   * @return array|string
   *   A string or render array to be used in output.
   */
  public function info();

  /**
   * Output message about status of the item.
   *
   * @return string|null
   *   The message.
   */
  public function log();

}
