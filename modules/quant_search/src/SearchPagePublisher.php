<?php

namespace Drupal\quant_search;

use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\Utility;
use Drupal\quant_api\Credentials;

/**
 * Publishes a saved search page: facets, route and the page on Quant.
 *
 * Called from the search page entity hooks, so it runs on every save.
 */
class SearchPagePublisher {

  public function __construct(
    protected SearchBackend $backend,
    protected RouteBuilderInterface $routeBuilder,
    protected Credentials $credentials,
  ) {}

  /**
   * Rebuilds the router, registers the facets and publishes or unpublishes.
   *
   * Runs whenever a page is saved, whatever saved it (see the entity hooks in
   * quant_search.module).
   *
   * @param \Drupal\quant_search\QuantSearchPageInterface $page
   *   The saved page.
   * @param bool $is_new
   *   Whether the page was just created (so never published before).
   * @param string|null $previous_route
   *   The route the page had before this save, if it was published there. It
   *   is unpublished when the page moved.
   */
  public function publish(QuantSearchPageInterface $page, bool $is_new, ?string $previous_route = NULL): void {
    // A new or changed path needs a route before Quant fetches it.
    $this->routeBuilder->rebuild();
    if (!$this->credentials->hasToken()) {
      return;
    }
    $route = (string) $page->get('route');
    if ($previous_route !== NULL && $previous_route !== $route) {
      Utility::unpublishUrl('/' . $previous_route, 'Unpublished search page');
    }
    if ($page->status()) {
      // The API must know the facets before the page queries them.
      $this->backend->registerFacets((array) $page->get('facets'));
      (new RouteItem(['route' => $route]))->send();
    }
    elseif (!$is_new) {
      Utility::unpublishUrl('/' . $route, 'Unpublished search page');
    }
  }

}
