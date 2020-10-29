<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Seed;
use UnexpectedValueException;

/**
 * A quant queue item for a redirect.
 *
 * @ingroup quant
 */
class RouteItem implements QuantQueueItemInterface {

  /**
   * A Drupal entity.
   *
   * @var Drupal\Core\Entity\EntityInterface
   */
  private $route;

  /**
   * {@inheritdoc}
   */
  public function __construct($route) {
    if (!is_string($route)) {
      throw new UnexpectedValueException(self::class . ' requires a string value.');
    }

    $this->route = $route;
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    $response = Seed::markupFromRoute($this->route);
    list($markup, $content_type) = $response;
    $config = \Drupal::config('quant.settings');
    $proxy_override = boolval($config->get('proxy_override', FALSE));

    $meta = [
      'info' => [
        'author' => '',
        'log' => '',
      ],
      'published' => TRUE,
      'transitions' => [],
      'proxy_override' => $proxy_override,
      'content_timestamp' => time(),
      'content_type' => $content_type,
    ];

    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $this->route, $meta));
  }

  /**
   * {@inheritdoc}
   */
  public function info() {
    return [
      '#type' => 'markup',
      '#markup' => '<b>Route</b>: ' . $this->route,
    ];
  }

}
