<?php

/**
 * @file
 * Contains \Drupal\quant\Plugin\QueueItem\NodeItem.
 */

namespace Drupal\quant\Plugin\QueueItem;

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
   * @var \Drupal\Core\Entity\EntityInterface
   */
  private $node;

  /**
   * The language code for the entity.
   *
   * @var string
   */
  private $lang;

  /**
   * {@inheritdoc}
   */
  public function __construct($entity) {
    $node = is_array($entity) ? reset($entity) : $entity;
    $this->node = $node;
    $this->lang = $node->language()->getId();
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($this->node, $this->lang));
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    return [
      '#type' => '#markup',
      '#markup' => '<b>Node ID:</b> ' . $this->node->id() . '<br/><b>Revision:</b> ' . $this->node->getLoadedRevisionId(),
    ];
  }

}
