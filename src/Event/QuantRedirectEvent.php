<?php

namespace Drupal\quant\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * The transport event (redirects).
 *
 * This event is triggered when redirect add/updates occur.
 *
 * @package \Drupal\quant\Event
 */
final class QuantRedirectEvent extends Event {

  /**
   * Allow modules to hook into the transport event.
   *
   * This allows modules to redirect the outupt to different storage
   * locations.
   *
   * @var string
   */
  const UPDATE = 'quant.redirect.update';

  /**
   * The location (source url)
   *
   * @var string
   */
  protected $source;

  /**
   * The destination (destination url).
   *
   * @var string
   */
  protected $destination;

  /**
   * The redirect status code.
   *
   * @var int
   */
  protected $statusCode;

  /**
   * {@inheritdoc}
   */
  public function __construct($source, $destination, $statusCode = 301) {
    $this->source = $source;
    $this->destination = $destination;
    $this->statusCode = $statusCode;
  }

  /**
   * Get the source URL.
   *
   * @return string
   *   The source URL.
   */
  public function getSourceUrl() : string {
    return $this->source;
  }

  /**
   * Get the destination URL.
   *
   * @return string
   *   The destination URL.
   */
  public function getDestinationUrl() : string {
    return $this->destination;
  }

  /**
   * Get the status code.
   *
   * @return int
   *   The status code.
   */
  public function getStatusCode() {
    return $this->statusCode;
  }

}
