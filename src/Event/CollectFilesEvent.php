<?php

namespace Drupal\quant\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectFilesEvent extends Event {

  /**
   * A list of file paths to send to quant.
   *
   * @var array
   */
  protected $filePaths;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $filePaths = []) {
    $this->filePaths = $filePaths;
  }

  /**
   * Add an entity to the exportlist.
   *
   * @var string $path
   *   The entity object.
   *
   * @return self
   */
  public function addFilePath($path) {
    $this->filePaths[] = $path;
    return $this;
  }

  /**
   * Get an entity from the evetn.
   *
   * @return mixed
   */
  public function getFilePath() {
    return array_shift($this->filePaths);
  }

}
