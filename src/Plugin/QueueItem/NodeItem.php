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

    // @TODO: This ideally should be a single entity (lang/rid) however
    // we want this to be as removed from the initial request flow as possible
    // to reduce OOM errors, this may still run into issues with large numbers
    // of translations and revisions.
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
    return [
      '#type' => '#markup',
      '#markup' => '<b>Node ID:</b> ' . $this->id,
    ];
  }

}
