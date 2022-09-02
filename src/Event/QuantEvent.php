<?php

namespace Drupal\quant\Event;

use Drupal\Component\EventDispatcher\Event;

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
   * Event name for unpublish.
   *
   * @var string
   */
  const UNPUBLISH = 'quant.unpublish';

  /**
   * The markup string.
   *
   * @var string
   */
  protected $contents;

  /**
   * The location.
   *
   * @var string
   */
  protected $location;

  /**
   * The metadata array.
   *
   * @var array
   */
  protected $meta;

  /**
   * Entity revision id.
   *
   * @var int
   */
  protected $rid;

  /**
   * The entity itself.
   *
   * @var Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The langcode associated with the event.
   *
   * @var string
   */
  protected $langcode;

  /**
   * {@inheritdoc}
   */
  public function __construct($contents, $location, $meta, $rid = NULL, $entity = NULL, $langcode = NULL) {
    $this->contents = $contents;
    $this->location = $location;
    $this->meta = $meta;
    $this->rid = $rid;
    $this->entity = $entity;
    $this->langcode = $langcode;
  }

  /**
   * Get the contents string.
   *
   * @return string
   *   The contents.
   */
  public function getContents() :? string {
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
   * Get the revision id associated.
   *
   * @return int
   *   The revision id.
   */
  public function getRevisionId() {
    return $this->rid;
  }

  /**
   * Get the langcode associated with the event.
   *
   * @return string
   *   The langcode.
   */
  public function getLangcode() {
    return $this->langcode;
  }

  /**
   * Get the metadata associated with the event.
   *
   * @return array
   *   The metadata.
   */
  public function getMetadata() {
    return $this->meta;
  }

  /**
   * Set the metadata associated with the event.
   */
  public function setMetadata($meta) {
    $this->meta = $meta;
  }

  /**
   * Entity getter.
   */
  public function getEntity() {
    return $this->entity;
  }

}
