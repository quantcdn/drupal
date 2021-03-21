<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\node\Entity\Node;
use Drupal\quant\Event\NodeInsertEvent;

/**
 * A standard definition for a queue item.
 *
 * @ingroup quant
 */
class NodeItem implements QuantQueueItemInterface {

  /**
   * A Drupal entity.
   *
   * @var int
   */
  private $id;

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
   */
  private $revisions;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $this->id = $data['id'];
    $this->filter = array_filter($data['lang_filter']);
    $this->revisions = $data['revisions'];
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    // @TOOD: This should be able to be generic entity.
    $entity = Node::load($this->id);

    foreach ($entity->getTranslationLanguages() as $langcode => $language) {
      if (!empty($this->filter) && !in_array($langcode, $this->filter)) {
        // Skip languages excluded from the filter.
        continue;
      }

      if (!$this->revisions) {
        \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($entity, $langcode));
        continue;
      }

      $vids = \Drupal::entityTypeManager()->getStorage('node')->revisionIds($entity);
      foreach ($vids as $vid) {
        $nr = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($vid);
        if ($nr->hasTranslation($langcode) && $nr->getTranslation($langcode)->isRevisionTranslationAffected()) {
          $nr = $nr->getTranslation($langcode);
          \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($nr, $langcode));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    $info = [
      '#type' => '#markup',
      '#markup' => '<b>Node ID:</b> ' . $this->id
    ];

    if ($this->revisions) {
      $info['#markup'] .= ' (including revisions)';
    }

    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function log($phase = 'start') {
    $message = '[node_item] - node_id: ' . ($this->id);
    if (!empty($this->revisions)) {
      $message .= " including revisions";
    }

    return $message;
  }

}
