<?php

/**
 * @file
 * Contains \Drupal\quant\Plugin\QueueItem\QuantQueueItemInterface.
 */

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

}
