<?php

namespace Drupal\quant\Event;

use Drupal\quant\Plugin\QueueItem\NodeItem;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectEntitiesEvent extends ConfigFormEventBase {

  /**
   * {@inheritdoc}
   */
  protected $queueItemClass = NodeItem::class;

  /**
   * Determine if we have revisions to seed.
   *
   * @return bool
   *   Include revisions or not.
   */
  public function includeRevisions() {
    return (bool) $this->getFormState()->getValue('entity_node_revisions');
  }

  /**
   * Determine if should seed the latest revision.
   *
   * @return bool
   *   Include latest revision or not.
   */
  public function includeLatest() {
    return (bool) $this->getFormState()->getValue('entity_node');
  }

}
