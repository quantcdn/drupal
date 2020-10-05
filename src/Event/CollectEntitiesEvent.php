<?php

namespace Drupal\quant\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectEntitiesEvent extends ConfigFormEventBase {

  /**
   * A list of entity ids that are to be exported.
   *
   * @var array
   *
   * @TODO: See memory usage by storing a class list
   * of all entities. We might need to simplify this
   * hash to be [id, type].
   */
  protected $entities;


  /**
   * Include revisions.
   *
   * @var bool
   */
  protected $revisions;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $entities = [], $revisions = TRUE, FormStateInterface $state = NULL) {
    parent::__construct($state);
    $this->entities = $entities;
    $this->revisions = $revisions;
  }

  /**
   * Return if the revisions are required.
   *
   * @return bool
   *   If revisions are to be exported.
   */
  public function includeRevisions() {
    return (bool) $this->revisions;
  }

  /**
   * Add an entity to the exportlist.
   *
   * @var mixed $entity
   *   The entity object.
   * @var string $langcode
   *   The language code of the entity.
   *
   * @return self
   *   The collection event instance.
   */
  public function addEntity($entity, $langcode = NULL) {
    $this->entities[] = [
      'entity' => $entity,
      'langcode' => $langcode,
    ];

    return $this;
  }

  /**
   * Get an entity from the evetn.
   *
   * @return mixed
   *   An entity.
   */
  public function getEntity() {
    return array_shift($this->entities);
  }

  /**
   * The total number of entities found.
   *
   * @return int
   *   The count.
   */
  public function total() {
    return count($this->entities);
  }

}
