<?php

namespace Drupal\Tests\quant_search\Functional;

use Drupal\quant_search\QuantSearchPageInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Saving, disabling or deleting a search page updates its page on Quant.
 *
 * Node saves publish from an entity hook, so they reach Quant whatever saved
 * them. Search pages published only from their two forms, so a page saved
 * any other way (drush, a recipe, a config import, custom code) stayed stale
 * on Quant until the next seed.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class SearchPagePublishTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'file',
    'field',
    'taxonomy',
    'block',
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
    $this->config('quant.settings')->set('local_server', $this->baseUrl)->save();
    $this->config('quant.token_settings')->set('disable', 1)->save();
  }

  /**
   * Creates an enabled search page at /search.
   */
  protected function createPage(): QuantSearchPageInterface {
    $page = \Drupal::entityTypeManager()->getStorage('quant_search_page')->create([
      'id' => 'search',
      'label' => 'Search',
      'title' => 'Search',
      'route' => 'search',
      'status' => TRUE,
      'facets' => [],
      'display' => [
        'results' => ['display_search' => TRUE, 'display_stats' => TRUE, 'show_clear_refinements' => TRUE],
        'pagination' => ['pagination_enabled' => TRUE, 'per_page' => 20],
      ],
    ]);
    $page->save();
    return $page;
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
   * Clears the recorded API calls.
   */
  protected function clearCalls(): void {
    \Drupal::state()->delete('quant_search_test.calls');
  }

  /**
   * A page saved in code publishes, when created and when changed.
   */
  public function testSaveInCodePublishes(): void {
    $this->clearCalls();
    $page = $this->createPage();
    $this->assertSame(['/search'], $this->published(), 'A new page is published.');

    $this->clearCalls();
    $page->set('title', 'Find a page')->save();
    $this->assertSame(['/search'], $this->published(), 'A changed page is published again.');
    $this->assertSame([], $this->unpublished());
  }

  /**
   * A disabled page is unpublished, and published again when enabled.
   */
  public function testDisableUnpublishes(): void {
    $page = $this->createPage();

    $this->clearCalls();
    $page->disable()->save();
    $this->assertSame(['/search'], $this->unpublished());
    $this->assertSame([], $this->published());

    $this->clearCalls();
    $page->enable()->save();
    $this->assertSame(['/search'], $this->published());
  }

  /**
   * A page that moves publishes its new address and withdraws the old one.
   */
  public function testRouteChangeUnpublishesTheOldAddress(): void {
    $page = $this->createPage();

    $this->clearCalls();
    $page->set('route', 'find')->save();
    $this->assertSame(['/find'], $this->published());
    $this->assertSame(['/search'], $this->unpublished());
  }

  /**
   * A deleted page is unpublished.
   */
  public function testDeleteUnpublishes(): void {
    $page = $this->createPage();

    $this->clearCalls();
    $page->delete();
    $this->assertSame(['/search'], $this->unpublished());
  }

  /**
   * A save from the edit form publishes the page once.
   */
  public function testEditFormPublishesOnce(): void {
    $this->createPage();
    $this->drupalLogin($this->drupalCreateUser(['administer quant search', 'access content']));

    $this->clearCalls();
    $this->drupalGet('admin/config/development/quant/search/pages/search');
    $this->submitForm(['title' => 'Find a page'], 'Save');
    $this->assertSession()->pageTextContains('search page updated');
    $this->assertSame(['/search'], $this->published());
  }

}
