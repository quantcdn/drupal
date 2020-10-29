<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Event\QuantFileEvent;

/**
 * A quant queue file item.
 *
 * @ingroup quant
 */
class FileItem implements QuantQueueItemInterface {

  /**
   * A filepath.
   *
   * @var string
   */
  private $file;

  /**
   * The language code for the entity.
   *
   * @var string
   */
  private $lang;

  /**
   * {@inheritdoc}
   */
  public function __construct($file) {
    $this->file = $file;
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    if (file_exists(DRUPAL_ROOT . $this->file)) {
      \Drupal::service('event_dispatcher')->dispatch(QuantFileEvent::OUTPUT, new QuantFileEvent(DRUPAL_ROOT . $this->file, $this->file));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    return ['#type' => 'markup', '#markup' => '<b>File: </b>' . $this->file];
  }

}
