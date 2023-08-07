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
   * URI for the file.
   *
   * @var string
   */
  private $uri;

  /**
   * Path to the file.
   *
   * @var string
   */
  private $filePath;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $data = []) {
    $route = $data['route'] ?? NULL;

    if (!is_string($route)) {
      throw new \UnexpectedValueException(self::class . ' requires a string value.');
    }

    // Ensure route starts with a slash and has no empty spaces.
    if (substr($route, 0, 1) != '/') {
      $route = "/{$route}";
    }
    $route = trim($route);

    $this->route = $route;
    $this->uri = $data['uri'] ?? strtok($route, '?');
    $this->filePath = $data['file_path'] ?? DRUPAL_ROOT . strtok($route, '?');
  }

  /**
   * {@inheritdoc}
   */
  public function send() {

    // Wrapper for routes that resolve as files.
    $extension = pathinfo($this->filePath, PATHINFO_EXTENSION);
    $response = FALSE;

    if (file_exists($this->filePath) && !empty($extension)) {
      if ($extension != 'html') {
        $file_item = new FileItem([
          'file' => $this->filePath,
          'url' => $this->uri,
        ]);
        $file_item->send();
        return;
      }
      // Get the content from the file.
      $response = [
        file_get_contents($this->filePath),
        'text/html; charset=UTF-8',
      ];
    }
    else {
      $response = Seed::markupFromRoute($this->route);
    }

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
