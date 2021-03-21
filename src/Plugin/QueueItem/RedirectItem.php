<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\redirect\Entity\Redirect;

/**
 * A standard definition for a queue item.
 *
 * @ingroup quant
 */
class RedirectItem implements QuantQueueItemInterface {

  /**
   * The entity id.
   *
   * @var int
   */
  protected $id;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $this->id = $data['id'];
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    $redirect = Redirect::load($this->id);

    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();
    $statusCode = $redirect->getStatusCode();

    \Drupal::service('event_dispatcher')->dispatch(QuantRedirectEvent::UPDATE, new QuantRedirectEvent($source, $destination, $statusCode));
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    $redirect = Redirect::load($this->id);

    $source = $redirect->getSourcePathWithQuery();
    $destination = $redirect->getRedirectUrl()->toString();

    return [
      '#type' => 'markup',
      '#markup' => '<b>Source: </b>' . $source . '<br/><b>Dest:</b> ' . $destination,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function log() {
    return '[redirect_item]: ' . $this->id;
  }

}
