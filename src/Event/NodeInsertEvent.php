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
   * Language code for export.
   *
   * @var string $langcode
   */

  /**
   * Constructs a node insertion demo event object.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   */
  public function __construct(EntityInterface $entity, $langcode=NULL) {
    $this->entity = $entity;
    $this->langcode = $langcode;
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

  /**
   * Get the language code associated with the event.
   *
   * @return string
   */
  public function getLangcode() {
    return $this->langcode;
  }

}
