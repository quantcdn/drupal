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
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $this->file = $data['file'];
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

  /**
   * {@inheritdoc}
   */
  public function log() {
    return '[file_item]: ' . $this->file;
  }

}
