<?php

namespace Drupal\quant_search\Routing;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Class EntityOverview
 *
 * @package Drupal\quant_search\Routing
 */
class QuantSearchRoutes
{

    /**
     * Dynamically generate the routes for the entity details.
     *
     * @return \Symfony\Component\Routing\RouteCollection
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     */
    public function searchPageRoutes()
    {
        $collection = new RouteCollection();

        $storage = \Drupal::entityTypeManager()->getStorage('quant_search_page');
        $ids = \Drupal::entityQuery('quant_search_page')->execute();
        $pages = $storage->loadMultiple($ids);

        foreach ($pages as $page) {

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
                    '_permission' => 'access content'
                ]
            );
            $collection->add("entity.quant_search_page.{$page->id()}", $route);
        }
        return $collection;
    }
}
