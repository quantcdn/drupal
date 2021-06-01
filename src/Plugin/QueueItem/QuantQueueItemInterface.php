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
