<?php

namespace Drupal\quant\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectFilesEvent extends ConfigFormEventBase {

  /**
   * A list of file paths to send to quant.
   *
   * @var array
   */
  protected $filePaths;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $filePaths = [], FormStateInterface $state = NULL) {
    parent::__construct($state);
    $this->filePaths = $filePaths;
  }

  /**
   * Add an entity to the exportlist.
   *
   * @var string $path
   *   The entity object.
   *
   * @return self
   *   The event instance.
   */
  public function addFilePath($path) {
    $this->filePaths[] = $path;
    return $this;
  }

  /**
   * Get an entity from the evetn.
   *
   * @return string
   *   A valid file path.
   */
  public function getFilePath() {
    return array_shift($this->filePaths);
  }

}
