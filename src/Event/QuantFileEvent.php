<?php

namespace Drupal\quant\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * The transport event (files).
 *
 * This event is triggered during a file export to Quant.
 *
 * @package \Drupal\quant\Event
 */
final class QuantFileEvent extends Event {

  /**
   * Allow modules to hook into the transport event.
   *
   * This allows modules to redirect the outupt to different storage
   * locations.
   *
   * @var string
   */
  const OUTPUT = 'quant.file.output';

  /**
   * The location (file on disk)
   *
   * @var string
   */
  protected $file;

  /**
   * The location (url path).
   *
   * @var string
   */
  protected $url;

  /**
   * Entity revision id.
   *
   * @var int
   */
  protected $rid;

  /**
   * {@inheritdoc}
   */
  public function __construct($file, $url, $rid = NULL) {
    $this->file = $file;
    $this->url = $url;
    $this->rid = $rid;
  }

  /**
   * Get the file location on disk.
   *
   * @return string
   *   The file on disk.
   */
  public function getFilePath() : string {
    // @todo Do this properly.
    return $this->file;
  }

  /**
   * Get the location string.
   *
   * @return string
   *   The location.
   */
  public function getUrl() : string {
    return $this->url;
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

}
