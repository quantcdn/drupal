<?php

namespace Drupal\quant\Event;

use Drupal\quant\Plugin\QueueItem\RouteItem;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectRoutesEvent extends ConfigFormEventBase {

  /**
   * {@inheritdoc}
   */
  protected $queueItemClass = RouteItem::class;

}
