<?php

namespace Drupal\quant\Event;

use Drupal\quant\Plugin\QueueItem\FileItem;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectFilesEvent extends ConfigFormEventBase {

  /**
   * {@inheritdoc}
   */
  protected $queueItemClass = FileItem::class;

}
