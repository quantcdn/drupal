<?php

namespace Drupal\quant_api\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Integrate with the QuantAPI to store static assets.
 */
class QuantApi implements EventSubscriberInterface {

  /**
   * The HTTP client to make API requests.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface;
   */
  protected $client;

  /**
   * QuantAPI event subcsriber.
   *
   * Listens to Quant events and triggers requests to the configured
   * API endpoint for different operations.
   *
   * @param \Drupal\quant_api\Client\QuantClientInterface $client
   *   The Drupal HTTP Client to make requests.
   */
  public function __construct(QuantClientInterface $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantEvent::OUTPUT][] = ['onOutput'];
    return $events;
  }

  /**
   * Trigger an API request with the event data.
   *
   * @param Drupal\quant\Event\QuantEvent $event
   *   The event.
   */
  public function onOutput(QuantEvent $event) {

  }

}
