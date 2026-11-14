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
   * @var array<string, array{route: string, status: bool, facets: array, new: bool, previous: ?string}>
   */
  protected array $pending = [];

  /**
   * Whether the shutdown function is registered for the pending pages.
   */
  protected bool $registered = FALSE;

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
    if (!$this->publishes($id)) {
      return;
    }
    if (isset($this->pending[$id])) {
      // A page saved twice in one request: what was live before the first
      // save decides whether it is new and which old address to withdraw.
      $item['new'] = $this->pending[$id]['new'];
      $item['previous'] = $this->pending[$id]['previous'];
    }
    $this->pending[$id] = $item;
    if (!$this->registered) {
      $this->registered = TRUE;
      drupal_register_shutdown_function('quant_shutdown', [
        'callback' => [$this, 'publishPending'],
        'args' => NULL,
        'request' => $this->requestStack->getCurrentRequest(),
      ]);
    }
  }

  /**
   * Whether a change of this page goes to Quant at all.
   *
   * Quant must be enabled (quant.settings:quant_enabled, as for nodes) and
   * connected. During installation and in maintenance mode the change is
   * left for a later save or seed.
   */
  protected function publishes(string $id): bool {
    if (!$this->configFactory->get('quant.settings')->get('quant_enabled') || !$this->credentials->hasToken()) {
      return FALSE;
    }
    if (InstallerKernel::installationAttempted() || $this->state->get('system.maintenance_mode')) {
      $this->loggerFactory->get('quant_search')->notice('Search page @id was not published while the site is being installed or is in maintenance mode. Save the page again or seed to publish it.', ['@id' => $id]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Publishes the pending pages. Runs as a shutdown function.
   */
  public function publishPending(): void {
    $pending = $this->pending;
    $this->pending = [];
    $this->registered = FALSE;
    if ($pending === []) {
      return;
    }
    // Under drush, resolve the domain's project before anything is sent.
    CliDomainContext::initialize();
    $this->routeBuilder->rebuildIfNeeded();
    foreach ($pending as $id => $item) {
      try {
        $this->publishItem($item);
      }
      catch (\Exception $error) {
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
    if ($previous === NULL || $previous === $item['route'] || $this->routeInUse($previous)) {
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
    return (bool) $this->entityTypeManager->getStorage('quant_search_page')->loadByProperties([
      'route' => $route,
      'status' => TRUE,
    ]);
  }

}
