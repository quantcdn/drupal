<?php

namespace Drupal\quant\Event;

use Drupal\Core\Entity\EntityInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * The transport event.
 *
 * This event is triggered during the storage process of exportable
 * entity.
 *
 * @package \Drupal\quant\Event
 */
final class QuantEvent extends Event {

  /**
   * Allow modules to transform the string.
   *
   * @var string
   */
  const TRANSFORM = 'quant.transform';

  /**
   * Allow modules to hook into the transport event.
   *
   * This allows modules to redirect the outupt to different storage
   * locations.
   *
   * @var string
   */
  const OUTPUT = 'quant.output';

  /**
   * The markup string.
   *
   * @var string
   */
  protected $contents;

  /**
   * The entity object.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The location.
   *
   * @var string
   */
  protected $location;

  /**
   * {@inheritdoc}
   */
  public function __construct($contents, $location, EntityInterface $entity) {
    $this->contents = $contents;
    $this->location = $location;
    $this->entity = $entity;
  }

  /**
   * Get the contents string.
   *
   * @return string
   *   The contents.
   */
  public function getContents() : string {
    return $this->contents;
  }

  /**
   * Get the location string.
   *
   * @return string
   *   The location.
   */
  public function getLocation() : string {
    return $this->location;
  }

  /**
   * Get the inserted entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity.
   */
  public function getEntity() {
    return $this->entity;
  }

}
