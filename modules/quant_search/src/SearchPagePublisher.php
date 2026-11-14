<?php

namespace Drupal\quant_search;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Installer\InstallerKernel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\State\StateInterface;
use Drupal\quant\CliDomainContext;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\Utility;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\PushFailures;
use Drupal\quant_search\Entity\QuantSearchPage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Publishes saved search pages on Quant: facets, the page, old addresses.
 *
 * The search page entity hooks call this on every insert, update and delete,
 * whatever made the change (a form, drush, a recipe, a config import). Like a
 * node save, the work runs after the request in a shutdown function: a config
 * import is then complete when the page renders, several saves of one page
 * publish once, and a Quant error never breaks the save or the import.
 */
class SearchPagePublisher {

  /**
   * The pages to publish at the end of the request, keyed by page ID.
   *
   * Static, so that a container rebuild during a config import (a module
   * installed by the import) does not split one page over two instances.
   *
   * @var array<string, array{route: string, status: bool, facets: array, new: bool, previous: ?string}>
   */
  protected static array $pending = [];

  /**
   * Whether the shutdown function is registered for the pending pages.
   */
  protected static bool $registered = FALSE;

  public function __construct(
    protected SearchBackend $backend,
    protected RouteBuilderInterface $routeBuilder,
    protected Credentials $credentials,
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected PushFailures $failures,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EventDispatcherInterface $eventDispatcher,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Schedules a saved page for publishing at the end of the request.
   *
   * @param \Drupal\quant_search\QuantSearchPageInterface $page
   *   The saved page.
   * @param bool $is_new
   *   Whether the page was just created (so never published before).
   * @param string|null $previous_route
   *   The route the page was published at before this save, if any.
   */
  public function schedule(QuantSearchPageInterface $page, bool $is_new, ?string $previous_route = NULL): void {
    // A new or changed path needs a route. The router rebuilds at the end of
    // the request, or before the publish below.
    $this->routeBuilder->setRebuildNeeded();
    $this->queue((string) $page->id(), [
      'route' => (string) $page->get('route'),
      'status' => (bool) $page->status(),
      'facets' => (array) $page->get('facets'),
      'new' => $is_new,
      'previous' => $previous_route,
    ]);
  }

  /**
   * Schedules a deleted page for unpublishing at the end of the request.
   */
  public function scheduleDelete(QuantSearchPageInterface $page): void {
    // The deleted page's route goes with it.
    $this->routeBuilder->setRebuildNeeded();
    $this->queue((string) $page->id(), [
      'route' => (string) $page->get('route'),
      'status' => FALSE,
      'facets' => [],
      'new' => FALSE,
      'previous' => NULL,
    ]);
  }

  /**
   * Adds a page to the pending pages; the first save keeps its old state.
   */
  protected function queue(string $id, array $item): void {
    if (!$this->connected() || $this->deferredBySiteState($item)) {
      return;
    }
    if (isset(static::$pending[$id])) {
      // A page saved twice in one request: what was live before the first
      // save decides whether it is new and which old address to withdraw.
      $item['new'] = static::$pending[$id]['new'];
      $item['previous'] = static::$pending[$id]['previous'];
    }
    static::$pending[$id] = $item;
    if (!static::$registered) {
      static::$registered = TRUE;
      drupal_register_shutdown_function('quant_shutdown', [
        'callback' => [static::class, 'publishPendingPages'],
        'args' => NULL,
        'request' => $this->requestStack->getCurrentRequest(),
      ]);
    }
  }

  /**
   * Whether Quant is enabled (as for nodes) and the site is connected.
   */
  protected function connected(): bool {
    // Under drush, the domain's settings and token decide, so resolve the
    // domain before reading them.
    CliDomainContext::initialize();
    return $this->configFactory->get('quant.settings')->get('quant_enabled') && $this->credentials->hasToken();
  }

  /**
   * Leaves a change made during installation or in maintenance mode.
   *
   * The change is listed with the pushes that failed, so editors see that it
   * is not on Quant until the page is saved again or the site is seeded.
   *
   * @return bool
   *   Whether the change is left.
   */
  protected function deferredBySiteState(array $item): bool {
    if (!InstallerKernel::installationAttempted() && !$this->state->get('system.maintenance_mode')) {
      return FALSE;
    }
    $url = '/' . $item['route'];
    $this->loggerFactory->get('quant_search')->notice('The search page at @url was not published while the site is being installed or is in maintenance mode. Save the page again or seed to publish it.', ['@url' => $url]);
    $this->failures->record($url, $item['status'] ? 'publish' : 'unpublish', new \RuntimeException('Not sent'), 'not sent during installation or maintenance mode');
    return TRUE;
  }

  /**
   * Publishes the pending pages with the current publisher service.
   *
   * The shutdown callback. It asks the container for the service, so the work
   * uses the services of the container that is current at the end.
   */
  public static function publishPendingPages(): void {
    \Drupal::service('quant_search.page_publisher')->publishPending();
  }

  /**
   * Publishes the pending pages.
   */
  public function publishPending(): void {
    $pending = static::$pending;
    static::$pending = [];
    static::$registered = FALSE;
    if ($pending === []) {
      return;
    }
    // Under drush, resolve the domain's project before anything is sent.
    CliDomainContext::initialize();
    try {
      $this->routeBuilder->rebuildIfNeeded();
    }
    catch (\Throwable $error) {
      $this->loggerFactory->get('quant_search')->error('The router was not rebuilt before the search pages were published: @message', ['@message' => $error->getMessage()]);
    }
    foreach ($pending as $id => $item) {
      try {
        $this->publishItem($item);
      }
      catch (\Throwable $error) {
        $this->loggerFactory->get('quant_search')->error('Search page @id was not published: @message', [
          '@id' => $id,
          '@message' => $error->getMessage(),
        ]);
        $this->failures->record('/' . $item['route'], 'publish', $error);
      }
    }
  }

  /**
   * Publishes or unpublishes one page, and withdraws its old address.
   */
  protected function publishItem(array $item): void {
    $this->retirePrevious($item);
    if ($item['status']) {
      // The API must know the facets before the page queries them.
      $this->backend->registerFacets($item['facets']);
      (new RouteItem(['route' => $item['route']]))->send();
      return;
    }
    if (!$item['new']) {
      $this->unpublish($item['route']);
    }
  }

  /**
   * Withdraws the address a moved page had, and redirects it to the new one.
   *
   * An address that another enabled page uses now (two pages swapped their
   * addresses) stays.
   */
  protected function retirePrevious(array $item): void {
    $previous = $item['previous'];
    // Quant paths do not depend on case: /Search and /search are one page.
    if ($previous === NULL || strtolower($previous) === strtolower($item['route']) || $this->routeInUse($previous)) {
      return;
    }
    Utility::unpublishUrl('/' . $previous, 'Unpublished search page');
    if ($item['status']) {
      $this->eventDispatcher->dispatch(new QuantRedirectEvent('/' . $previous, '/' . $item['route'], 301), QuantRedirectEvent::UPDATE);
    }
  }

  /**
   * Unpublishes an address, unless an enabled page uses it now.
   */
  protected function unpublish(string $route): void {
    if (!$this->routeInUse($route)) {
      Utility::unpublishUrl('/' . $route, 'Unpublished search page');
    }
  }

  /**
   * Whether an enabled search page has this route.
   */
  protected function routeInUse(string $route): bool {
    $route = QuantSearchPage::normaliseRoute($route);
    foreach ($this->entityTypeManager->getStorage('quant_search_page')->loadByProperties(['status' => TRUE]) as $page) {
      if (QuantSearchPage::normaliseRoute((string) $page->get('route')) === $route) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
