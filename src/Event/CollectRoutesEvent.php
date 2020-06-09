<?php

namespace Drupal\quant\Event;

use Symfony\Component\EventDispatcher\Event;

/**
 * Collect entities event.
 *
 * This is triggered when we need to gather all entities
 * to export to Quant.
 */
class CollectRoutesEvent extends Event {

  /**
   * A list of entity ids that are to be exported.
   *
   * @TODO: See memory usage by storing a class list
   * of all entities. We might need to simplify this
   * hash to be [id, type].
   *
   * @var array
   */
  protected $routes;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $routes = []) {
    $this->routes = $routes;
  }

  /**
   * Add an entity to the exportlist.
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
