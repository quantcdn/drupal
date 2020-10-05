<?php

namespace Drupal\quant\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectRedirectsEvent extends ConfigFormEventBase {

  /**
   * A list of redirect entities that are to be exported.
   *
   * @var array
   */
  protected $entities;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $entities = [], FormStateInterface $state = NULL) {
    parent::__construct($state);
    $this->entities = $entities;
  }

  /**
   * Add an entity to the exportlist.
   *
   * @var mixed $entity
   *   The entity object.
   *
   * @return Drupal\quant\Event\CollectRedirectsEvent
   *   The redirect event.
   */
  public function addEntity($entity) {
    $this->entities[] = $entity;
    return $this;
  }

  /**
   * Get an entity from the evetn.
   *
   * @return mixed
   *   A valid entity for this event.
   */
  public function getEntity() {
    return array_shift($this->entities);
  }

}
