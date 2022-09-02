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
    $route = $data['route'] ?? NULL;

    if (!is_string($route)) {
      throw new \UnexpectedValueException(self::class . ' requires a string value.');
    }

    // Ensure route starts with a slash.
    if (substr($route, 0, 1) != '/') {
      $route = "/{$route}";
    }

    $this->route = trim($route);
  }

  /**
   * {@inheritdoc}
   */
  public function send() {

    // Wrapper for routes that resolve as files.
    $ext = pathinfo(strtok($this->route, '?'), PATHINFO_EXTENSION);

    if ($ext && file_exists(DRUPAL_ROOT . strtok($this->route, '?'))) {
      $file_item = new FileItem([
        'file' => strtok($this->route, '?'),
        'url' => $this->route,
        'full_path' => DRUPAL_ROOT . $this->route,
      ]);
      $file_item->send();
      return;
    }

    $response = Seed::markupFromRoute($this->route);

    if (!$response) {
      \Drupal::logger('quant_seed')->error("Unable to send {$this->route}");
      return;
    }

    [$markup, $content_type] = $response;

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

    \Drupal::service('event_dispatcher')->dispatch(new QuantEvent($markup, $this->route, $meta), QuantEvent::OUTPUT);
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
