<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Seed;

/**
 * A taxonomy term queue item.
 *
 * @ingroup quant
 */
class TaxonomyTermItem implements QuantQueueItemInterface {

  /**
   * The taxonomy term id.
   *
   * @var int
   */
  private $tid;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $this->tid = $data['tid'];
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($this->tid);

    foreach ($term->getTranslationLanguages() as $langcode => $language) {
      Seed::seedTaxonomyTerm($term, $langcode);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    return [
      '#type' => '#markup',
      '#markup' => "<b>Term ID:</b> {$this->tid}",
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function log($phase = 'start') {
    return "[taxonomy_term_item] - tid: {$this->tid}";
  }

}
