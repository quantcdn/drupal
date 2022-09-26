<?php

namespace Drupal\quant_search\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Generates Quant Search Page routes.
 *
 * @package Drupal\quant_search\Routing
 */
class QuantSearchRoutes {

  /**
   * Dynamically generate the routes for the Quant Search Page entities.
   *
   * @return \Symfony\Component\Routing\RouteCollection
   *   The search page routes.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function searchPageRoutes() {
    $collection = new RouteCollection();

    $storage = \Drupal::entityTypeManager()->getStorage('quant_search_page');
    $ids = \Drupal::entityQuery('quant_search_page')->accessCheck(TRUE)->execute();
    $pages = $storage->loadMultiple($ids);

    foreach ($pages as $page) {

      // Only process enabled pages.
      if (!$page->get('status')) {
        continue;
      }

      $route = new Route(
            $page->get('route'),
            [
              '_title' => $page->get('title'),
              '_controller' => '\Drupal\quant_search\Controller\Search::searchPage',
              'page' => $page,
            ],
            [
              '_permission' => 'access content',
            ]
        );
      $collection->add("entity.quant_search_page.{$page->id()}", $route);
    }
    return $collection;
  }

}
