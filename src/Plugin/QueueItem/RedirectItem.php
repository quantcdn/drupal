<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Event\QuantRedirectEvent;

/**
 * A standard definition for a queue item.
 *
 * @ingroup quant
 */
class RedirectItem implements QuantQueueItemInterface {

  /**
   * The source path.
   *
   * @var string
   */
  protected $source;

  /**
   * The destination path or URL.
   *
   * @var string
   */
  protected $destination;

  /**
   * The redirection status code.
   *
   * @var int
   */
  protected $statusCode;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $this->source = $data['source'];
    $this->destination = $data['destination'];
    $this->statusCode = $data['status_code'];
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    \Drupal::service('event_dispatcher')->dispatch(new QuantRedirectEvent($this->source, $this->destination, $this->statusCode), QuantRedirectEvent::UPDATE);
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    return [
      '#type' => 'markup',
      '#markup' => '<b>Source: </b>' . $this->source . '<br/><b>Dest:</b> ' . $this->destination,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function log() {
    return "[redirect_item]: Source: {$this->source}, Dest: {$this->destination}";
  }

}
