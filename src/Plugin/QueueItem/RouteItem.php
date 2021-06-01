<?php

namespace Drupal\quant\Plugin\QueueItem;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Seed;

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
  public function __construct(array $data = []) {
    $route = isset($data['route']) ? $data['route'] : NULL;

    if (!is_string($route)) {
      throw new \UnexpectedValueException(self::class . ' requires a string value.');
    }

    $this->route = $route;
  }

  /**
   * {@inheritdoc}
   */
  public function send() {
    $response = Seed::markupFromRoute($this->route);

    if (!$response) {
      \Drupal::logger('quant_seed')->error("Unable to send {$this->route}");
      return;
    }

    list($markup, $content_type) = $response;

    $config = \Drupal::config('quant.settings');
    $proxy_override = boolval($config->get('proxy_override', FALSE));

    $meta = [
      'info' => [
        'author_name' => '',
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

  /**
   * {@inheritdoc}
   */
  public function log() {
    return '[route_item] ' . $this->route;
  }

}
