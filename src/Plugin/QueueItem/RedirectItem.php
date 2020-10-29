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
   * The source URL for the redirect.
   *
   * @var string
   */
  private $source;

  /**
   * The destination URL for the redirect.
   *
   * @var string
   */
  private $destination;

  /**
   * HTTP status code for the redirect.
   *
   * @var int
   */
  private $statusCode;

  /**
   * {@inheritdoc}
   */
  public function __construct($redirect) {
    $this->source = $redirect->getSourcePathWithQuery();
    $this->destination = $redirect->getRedirectUrl()->toString();
    $this->statusCode = $redirect->getStatusCode();

  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($this->source, $this->destination, $this->statusCode));
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

}
