<?php

namespace Drupal\Tests\quant_search\Functional;

use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant_search\QuantSearchPageInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\quant_search\Traits\WaitsForQuantCallsTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Saving, disabling or deleting a search page updates its page on Quant.
 *
 * Node saves publish from an entity hook, so they reach Quant whatever saved
 * them. Search pages published only from their two forms, so a page saved
 * any other way (drush, a recipe, a config import, custom code) stayed stale
 * on Quant until the next seed.
 *
 * Like a node save, the publish runs after the request (a shutdown function),
 * so these tests run the Quant shutdown functions where the request would
 * end. The block module is not installed, which the create form must allow.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class SearchPagePublishTest extends BrowserTestBase {

  use WaitsForQuantCallsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'file',
    'field',
    'taxonomy',
    'quant',
    'quant_api',
    'quant_search',
    'quant_search_test',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  /**
   * The redirects dispatched in this process.
   *
   * @var string[]
   */
  protected array $redirects = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->config('quant_api.settings')
      ->set('api_account', 'test-org')
      ->set('api_project', 'test-project')
      ->set('api_token', 'test-token')
      ->save();
    $this->config('quant.settings')
      ->set('quant_enabled', TRUE)
      ->set('local_server', $this->baseUrl)
      ->save();
    $this->config('quant.token_settings')->set('disable', 1)->save();
    $this->container->get('event_dispatcher')->addListener(QuantRedirectEvent::UPDATE, function (QuantRedirectEvent $event) {
      $this->redirects[] = $event->getSourceUrl() . ' -> ' . $event->getDestinationUrl() . ' ' . $event->getStatusCode();
    });
  }

  /**
   * Creates an enabled search page.
   */
  protected function createPage(string $id = 'search', string $route = 'search', array $facets = []): QuantSearchPageInterface {
    $page = \Drupal::entityTypeManager()->getStorage('quant_search_page')->create([
      'id' => $id,
      'label' => 'Search',
      'title' => 'Search',
      'route' => $route,
      'status' => TRUE,
      'facets' => $facets,
      'display' => [
        'results' => ['display_search' => TRUE, 'display_stats' => TRUE, 'show_clear_refinements' => TRUE],
        'pagination' => ['pagination_enabled' => TRUE, 'per_page' => 20],
      ],
    ]);
    $page->save();
    return $page;
  }

  /**
   * Runs the Quant shutdown functions, as the end of the request would.
   */
  protected function endRequest(): void {
    $callbacks = &drupal_register_shutdown_function();
    $quant = ['quant_shutdown', '_quant_run_with_request'];
    do {
      $ran = FALSE;
      foreach ($callbacks as $key => $callback) {
        if (is_string($callback['callback']) && in_array($callback['callback'], $quant, TRUE)) {
          unset($callbacks[$key]);
          call_user_func_array($callback['callback'], $callback['arguments']);
          $ran = TRUE;
          break;
        }
      }
    } while ($ran);
  }

  /**
   * The paths published to Quant since the calls were last cleared.
   */
  protected function published(): array {
    $calls = \Drupal::state()->get('quant_search_test.calls', []);
    $pushes = array_filter($calls, fn ($call) => $call['method'] === 'POST' && $call['path'] === '/v1');
    return array_values(array_map(fn ($call) => $call['body']['url'] ?? '', $pushes));
  }

  /**
   * The paths unpublished on Quant since the calls were last cleared.
   */
  protected function unpublished(): array {
    $calls = \Drupal::state()->get('quant_search_test.calls', []);
    $unpublishes = array_filter($calls, fn ($call) => $call['path'] === '/v1/unpublish');
    return array_values(array_map(fn ($call) => $call['quant_url'], $unpublishes));
  }

  /**
   * Clears the recorded API calls and redirects.
   */
  protected function clearCalls(): void {
    \Drupal::state()->delete('quant_search_test.calls');
    $this->redirects = [];
  }

  /**
   * A page saved in code publishes once, after the request.
   */
  public function testSaveInCodePublishesAfterTheRequest(): void {
    $this->clearCalls();
    $page = $this->createPage();
    $this->assertSame([], $this->published(), 'Nothing is sent during the save.');
    $this->endRequest();
    $this->assertSame(['/search'], $this->published(), 'A new page is published.');

    $this->clearCalls();
    $page->set('title', 'Find a page')->save();
    $page->set('description', 'Two saves in one request.')->save();
    $this->endRequest();
    $this->assertSame(['/search'], $this->published(), 'A changed page is published again, once.');
    $this->assertSame([], $this->unpublished());
  }

  /**
   * A disabled page is unpublished, and published again when enabled.
   */
  public function testDisableUnpublishes(): void {
    $page = $this->createPage();
    $this->endRequest();

    $this->clearCalls();
    $page->disable()->save();
    $this->endRequest();
    $this->assertSame(['/search'], $this->unpublished());
    $this->assertSame([], $this->published());

    $this->clearCalls();
    $page->enable()->save();
    $this->endRequest();
    $this->assertSame(['/search'], $this->published());
  }

  /**
   * A page that moves withdraws the old address and redirects it.
   */
  public function testRouteChangeRedirectsTheOldAddress(): void {
    $page = $this->createPage();
    $this->endRequest();

    $this->clearCalls();
    $page->set('route', 'find')->save();
    $this->endRequest();
    $this->assertSame(['/find'], $this->published());
    $this->assertSame(['/search'], $this->unpublished());
    $this->assertSame(['/search -> /find 301'], $this->redirects);
  }

  /**
   * Two pages that swap addresses keep both addresses published.
   */
  public function testSwappedRoutesKeepBothAddresses(): void {
    $first = $this->createPage('first', 'search');
    $second = $this->createPage('second', 'find');
    $this->endRequest();

    $this->clearCalls();
    $first->set('route', 'find')->save();
    $second->set('route', 'search')->save();
    $this->endRequest();
    $this->assertEqualsCanonicalizing(['/find', '/search'], $this->published());
    $this->assertSame([], $this->unpublished());
    $this->assertSame([], $this->redirects);
  }

  /**
   * A deleted page is unpublished.
   */
  public function testDeleteUnpublishes(): void {
    $page = $this->createPage();
    $this->endRequest();

    $this->clearCalls();
    $page->delete();
    $this->endRequest();
    $this->assertSame(['/search'], $this->unpublished());
  }

  /**
   * With Quant turned off, a save, a disable and a delete send nothing.
   */
  public function testQuantDisabledSendsNothing(): void {
    $this->config('quant.settings')->set('quant_enabled', FALSE)->save();
    $this->clearCalls();
    $page = $this->createPage();
    $page->set('route', 'find')->save();
    $page->disable()->save();
    $page->delete();
    $this->endRequest();
    $this->assertSame([], \Drupal::state()->get('quant_search_test.calls', []));
  }

  /**
   * Without a project token, a save and a delete send nothing.
   */
  public function testNoTokenSendsNothing(): void {
    $this->config('quant_api.settings')->set('api_token', '')->save();
    $this->clearCalls();
    $page = $this->createPage();
    $page->set('title', 'Find a page')->save();
    $page->delete();
    $this->endRequest();
    $this->assertSame([], \Drupal::state()->get('quant_search_test.calls', []));
  }

  /**
   * A config import publishes a changed page once, after the import.
   */
  public function testConfigImportPublishesAfterTheImport(): void {
    $this->createPage();
    $this->endRequest();
    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    $data = $sync->read('quant_search.quant_search.search');
    $data['title'] = 'Imported title';
    $sync->write('quant_search.quant_search.search', $data);

    $this->clearCalls();
    $this->configImporter()->import();
    $this->assertSame('Imported title', $this->config('quant_search.quant_search.search')->get('title'));
    $this->assertSame([], $this->published(), 'Nothing is sent during the import.');
    $this->endRequest();
    $this->assertSame(['/search'], $this->published());
  }

  /**
   * A Quant API failure breaks neither a save nor a config import.
   */
  public function testApiFailureDoesNotBreakTheSaveOrTheImport(): void {
    $facets = [
      [
        'facet_display' => 'checkbox',
        'facet_type_config' => ['facet_type' => 'content_type', 'taxonomy_vocabulary' => '', 'custom_key' => ''],
        'facet_heading' => 'Content type',
        'facet_language' => 'en',
        'facet_limit' => 10,
        'weight' => 0,
      ],
    ];
    \Drupal::state()->set('quant_search_test.fail', TRUE);
    $this->createPage('search', 'search', $facets);
    $this->endRequest();
    $failures = $this->container->get('quant_api.push_failures')->all();
    $this->assertArrayHasKey('/search', $failures, 'The failure is recorded.');

    $active = $this->container->get('config.storage');
    $sync = $this->container->get('config.storage.sync');
    $this->copyConfig($active, $sync);
    $data = $sync->read('quant_search.quant_search.search');
    $data['title'] = 'Imported title';
    $sync->write('quant_search.quant_search.search', $data);
    $this->configImporter()->import();
    $this->endRequest();
    $this->assertSame('Imported title', $this->config('quant_search.quant_search.search')->get('title'));
  }

  /**
   * A save from the edit form publishes the page once.
   */
  public function testEditFormPublishesOnce(): void {
    $this->createPage();
    $this->endRequest();
    $this->drupalLogin($this->drupalCreateUser(['administer quant search', 'access content']));

    $this->clearCalls();
    $this->drupalGet('admin/config/development/quant/search/pages/search');
    $this->submitForm(['title' => 'Find a page'], 'Save');
    $this->assertSession()->pageTextContains('search page updated');
    $this->waitForPublish('/search');
    $this->assertSame(['/search'], $this->published());
  }

  /**
   * The edit form refuses an address that another search page uses.
   */
  public function testEditFormRefusesAnAddressInUse(): void {
    $this->createPage('first', 'search');
    $this->createPage('second', 'find');
    $this->endRequest();
    $this->drupalLogin($this->drupalCreateUser(['administer quant search', 'access content']));

    $this->drupalGet('admin/config/development/quant/search/pages/second');
    $this->submitForm(['route' => 'search'], 'Save');
    $this->assertSession()->pageTextContains('Another search page uses this address.');
    $this->assertSame('find', \Drupal::entityTypeManager()->getStorage('quant_search_page')->loadUnchanged('second')->get('route'));
  }

  /**
   * The create form works without the block module and publishes once.
   */
  public function testCreateFormPublishesOnceWithoutBlocks(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer quant search', 'access content']));
    $this->clearCalls();
    $this->drupalGet('admin/config/development/quant/search/pages/create');
    $this->assertSession()->fieldNotExists('place_block');
    $this->submitForm(['route' => 'search'], 'Create the search page');
    $this->assertSession()->pageTextContains('The search page is at /search.');
    $this->waitForPublish('/search');
    $this->assertSame(['/search'], $this->published());
  }

}
