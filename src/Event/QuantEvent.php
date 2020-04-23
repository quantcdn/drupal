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
   * @var integer
   */
  protected $rid;

  /**
   * {@inheritdoc}
   */
  public function __construct($contents, $location, $meta, $rid=null) {
    $this->contents = $contents;
    $this->location = $location;
    $this->meta = $meta;
    $this->rid = $rid;
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
   * Get the revision id associated.
   *
   * @return integer
   *   The revision id.
   */
  public function getRevisionId() {
    return $this->rid;
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

}
