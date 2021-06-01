<?php

namespace Drupal\quant\Event;

use Drupal\quant\Plugin\QueueItem\RedirectItem;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectRedirectsEvent extends ConfigFormEventBase {
  /**
   * {@inheritdoc}
   */
  protected $queueItemClass = RedirectItem::class;

}
