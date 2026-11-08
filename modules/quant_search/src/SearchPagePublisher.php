<?php

namespace Drupal\quant_search;

use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\Utility;
use Drupal\quant_api\Credentials;

/**
 * Publishes a saved search page: facets, route and the page on Quant.
 *
 * Used by the full search page form and by "Create a search page".
 */
class SearchPagePublisher {

  public function __construct(
    protected SearchBackend $backend,
    protected RouteBuilderInterface $routeBuilder,
    protected Credentials $credentials,
  ) {}

  /**
   * Registers the facets, rebuilds the router and publishes or unpublishes.
   *
   * @param \Drupal\quant_search\QuantSearchPageInterface $page
   *   The saved page.
   * @param bool $is_new
   *   Whether the page was just created (so never published before).
   */
  public function publish(QuantSearchPageInterface $page, bool $is_new): void {
    // The API must know the facets before the page queries them.
    $this->backend->registerFacets((array) $page->get('facets'));
    // A new or changed path needs a route before Quant fetches it.
    $this->routeBuilder->rebuild();
    if (!$this->credentials->hasToken()) {
      return;
    }
    $route = (string) $page->get('route');
    if ($page->status()) {
      (new RouteItem(['route' => $route]))->send();
    }
    elseif (!$is_new) {
      Utility::unpublishUrl('/' . $route, 'Unpublished search page');
    }
  }

}
