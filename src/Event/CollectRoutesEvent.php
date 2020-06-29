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
   * @return self
   */
  public function addRoute($route) {
    $this->routes[] = $route;
    return $this;
  }

  /**
   * Get an entity from the evetn.
   *
   * @return string
   */
  public function getRoute() {
    return array_shift($this->routes);
  }

}
