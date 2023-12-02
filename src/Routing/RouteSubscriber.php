<?php

namespace Drupal\quant\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Listens to the dynamic route events.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public function alterRoutes(RouteCollection $collection) {

    // Alter the canonical node route to our custom Quant route.
    if ($route = $collection->get('entity.node.canonical')) {
      $route->setDefault('_controller', '\Drupal\quant\Controller\QuantNodeViewController::view');
    }

    // Hide metadata redirect route if redirect module isn't installed.
    if ($route = $collection->get('quant.metadata_redirect')) {
      if (!\Drupal::moduleHandler()->moduleExists('redirect')) {
        // Note second parameter is a string.
        $route->setRequirement('_access', 'FALSE');
      }
    }

  }

}
