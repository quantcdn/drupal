<?php

namespace Drupal\quant\Event;

use Symfony\Component\EventDispatcher\Event;
use Drupal\Core\Entity\EntityInterface;

/**
 * Wraps a node insertion demo event for event listeners.
 */
class NodeInsertEvent extends Event {

  const NODE_INSERT_EVENT = 'event_subscriber.node.insert';

  /**
   * Node entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * Constructs a node insertion demo event object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   */
  public function __construct(EntityInterface $entity) {
    $this->entity = $entity;
  }

  /**
   * Get the inserted entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity object.
   */
  public function getEntity() {
    return $this->entity;
  }
}
