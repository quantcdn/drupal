<?php

namespace Drupal\quant\Event;

use Drupal\quant\Plugin\QueueItem\TaxonomyTermItem;

/**
 * Collect taxonomy terms event.
 *
 * This is triggered when we need to gather all taxonomy terms
 * to export to Quant.
 */
class CollectTaxonomyTermsEvent extends ConfigFormEventBase {

  /**
   * {@inheritdoc}
   */
  protected $queueItemClass = TaxonomyTermItem::class;

}
