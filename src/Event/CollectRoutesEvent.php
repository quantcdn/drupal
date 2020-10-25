<?php

namespace Drupal\quant\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectRoutesEvent extends ConfigFormEventBase {

  /**
   * A list of routes that are to be exported.
   *
   * @var array
   */
  protected $routes;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $routes = [], FormStateInterface $state = NULL) {
    parent::__construct($state);
    $this->routes = $routes;
  }

  /**
   * Get configuration values.
   *
   * @param string $key
   *   The setting value to get.
   */
  public function getSetting($key) {
    return isset($this->settings[$key]) ? $this->settings[$key] : FALSE;
  }

  /**
   * Add an entity to the export list.
   *
   * @var string $route
   *   The entity object.
   *
   * @return Drupal\quant\Event\CollectRoutesEvent
   *   The route collection event.
   */
  public function addRoute($route) {
    if ($route) {
      $this->routes[] = $route;
    }
    return $this;
  }

  /**
   * List all routes for the event.
   *
   * @return array
   *   The array route.
   */
  public function getRoutes() {
    return $this->routes;
  }

  /**
   * Get an route from the event.
   *
   * @return string
   *   A route.
   */
  public function getRoute() {
    return array_shift($this->routes);
  }

}
