<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Seed;

/**
 * A standard definition for a queue item.
 *
 * @ingroup quant
 */
class NodeItem implements QuantQueueItemInterface {

  /**
   * The entity id.
   *
   * @var int
   */
  private $id;

  /**
   * The revision id.
   *
   * @var int
   */
  private $vid;

  /**
   * The language code for the entity.
   *
   * @var array
   */
  private $filter;

  /**
   * Include entity revisions.
   *
   * @var bool
   * @todo Remove this?
   */
  private $revisions;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $this->id = $data['id'];
    $this->vid = $data['vid'] ?? FALSE;
    $this->filter = isset($data['lang_filter']) && is_array($data['lang_filter']) ? array_filter($data['lang_filter']) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    if (empty($this->vid)) {
      $entity = \Drupal::entityTypeManager()->getStorage('node')->load($this->id);
    }
    else {
      $entity = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($this->vid);
    }

    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      if (!empty($this->filter) && !in_array($langcode, $this->filter)) {
        continue;
      }
      Seed::seedNode($entity, $langcode);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    return [
      '#type' => '#markup',
      '#markup' => "<b>Node ID:</b> {$this->id}<br/><b>Revision</b> {$this->vid}",
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function log($phase = 'start') {
    return "[node_item] - node_id: {$this->id} vid: {$this->vid}";
  }

}
