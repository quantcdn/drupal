<?php

namespace Drupal\Tests\quant_search\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\quant_search\Form\CreateSearchPageForm;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests "Create a search page": defaults, facets, publish and block.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class CreateSearchPageTest extends BrowserTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'olivero';

  /**
   * The quant module's metadata views have schema gaps.
   *
   * The search page schema itself is checked in
   * testSavedPageMatchesTheSchema().
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);
    $this->drupalCreateContentType(['type' => 'event', 'name' => 'Event']);
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    Vocabulary::create(['vid' => 'regions', 'name' => 'Regions'])->save();
    $this->addTermField('article', 'field_topics', 'topics');
    $this->addTermField('event', 'field_region', 'regions');
    $this->config('quant_api.settings')
      ->set('api_account', 'test-org')
      ->set('api_project', 'test-project')
      ->set('api_token', 'test-token')
      ->save();
    $this->config('quant.settings')->set('local_server', $this->baseUrl)->save();
    $this->config('quant.token_settings')->set('disable', 1)->save();
    $this->drupalLogin($this->drupalCreateUser(['administer quant search', 'access content', 'administer blocks']));
  }

  /**
   * Adds a taxonomy reference field to a content type.
   */
  protected function addTermField(string $bundle, string $name, string $vid): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'settings' => ['handler_settings' => ['target_bundles' => [$vid => $vid]]],
    ])->save();
  }

  /**
   * One screen creates, publishes and links the page, with a search box.
   */
  public function testCreateWithDefaults(): void {
    $this->drupalGet('admin/config/development/quant/search/pages');
    $this->clickLink('Create a search page');
    $session = $this->assertSession();
    $session->fieldValueEquals('route', 'search');
    $session->checkboxChecked('edit-facets-content-type');
    $session->checkboxChecked('edit-facets-taxonomytopics');
    $session->checkboxChecked('edit-facets-taxonomyregions');
    $session->pageTextContains('Topics (used by Article)');

    \Drupal::state()->delete('quant_search_test.calls');
    $this->submitForm(['route' => '/Search/', 'bundles[event]' => FALSE], 'Create the search page');
    $session->pageTextContains('The search page is at /search.');

    $page = \Drupal::entityTypeManager()->getStorage('quant_search_page')->load('search');
    $this->assertTrue($page->status());
    $this->assertSame('search', $page->get('route'));
    $this->assertSame(['article' => 'article'], $page->get('bundles'));
    $types = array_map(fn ($facet) => $facet['facet_type_config']['facet_type'] . ':' . $facet['facet_type_config']['taxonomy_vocabulary'], $page->get('facets'));
    $this->assertSame(['content_type:', 'taxonomy:topics', 'taxonomy:regions'], $types);

    // The facets were registered and the page was published.
    $calls = \Drupal::state()->get('quant_search_test.calls', []);
    $facets = array_values(array_filter($calls, fn ($call) => $call['path'] === '/v1/search/facet'));
    $this->assertNotEmpty($facets);
    $this->assertContains('topics_en', $facets[0]['body']);
    $pushed = array_filter($calls, fn ($call) => $call['path'] === '/v1' && ($call['body']['url'] ?? '') === '/search');
    $this->assertNotEmpty($pushed, 'The search page was published.');

    // The default index already covers every type, so nothing changed and
    // no re-index is asked for. The search box is in the header.
    $this->assertEmpty(array_filter((array) $this->config('quant_search.entities.settings')->get('quant_search_entity_node_bundles')));
    $session->pageTextNotContains('Re-index the content');
    $block = \Drupal::entityTypeManager()->getStorage('block')->load('olivero_quant_search_search');
    $this->assertSame($page->uuid(), $block->get('settings')['page']);
    // The static pages show the box only after they are published again.
    $session->pageTextContains('publish the site again');
    $session->linkByHrefExists('/admin/config/quant/seed');

    // The page renders.
    $this->drupalGet('search');
    $session->statusCodeEquals(200);
    $session->elementExists('css', '#hits, .quant-search-page, [id^="quant-search"]');

    // A second page proposes another address: /search is taken now. The
    // header already has a search box, so a second one is not proposed.
    $this->drupalGet('admin/config/development/quant/search/pages/create');
    $session->fieldValueEquals('route', 'site-search');
    $session->checkboxNotChecked('edit-place-block');
    $session->pageTextContains('This theme already has a Quant search box');
  }

  /**
   * A path Drupal already uses, or a bad path, is refused.
   */
  public function testRouteValidation(): void {
    $this->drupalGet('admin/config/development/quant/search/pages/create');
    $this->submitForm(['route' => 'user/login'], 'Create the search page');
    $this->assertSession()->pageTextContains('Drupal already has a page at this address.');
    $this->submitForm(['route' => 'Search Page!'], 'Create the search page');
    $this->assertSession()->pageTextContains('Use lower-case letters');
    $this->assertSame('news_search', CreateSearchPageForm::machineName(CreateSearchPageForm::normaliseRoute('/News/Search/')));
  }

  /**
   * A page saved by either form is valid against the schema.
   */
  public function testSavedPageMatchesTheSchema(): void {
    $this->drupalGet('admin/config/development/quant/search/pages/create');
    $this->submitForm(['place_block' => FALSE], 'Create the search page');
    $typed = \Drupal::service('config.typed');
    $name = 'quant_search.quant_search.search';
    $this->assertTrue($typed->hasConfigSchema($name));
    $errors = $typed->createFromNameAndData($name, $this->config($name)->get())->validate();
    $this->assertCount(0, $errors, (string) $errors);
    $checker = new class() {
      use \Drupal\Core\Config\Schema\SchemaCheckTrait;

      /**
       * Runs the schema check.
       */
      public function run($typed, $name, $data) {
        return $this->checkConfigSchema($typed, $name, $data);
      }

    };
    $this->assertTrue($checker->run($typed, $name, $this->config($name)->get()));
  }

  /**
   * Turning indexing on for the page's types asks for a re-index.
   */
  public function testIndexIsExtendedForThePage(): void {
    $this->config('quant_search.entities.settings')
      ->set('quant_search_entity_node', TRUE)
      ->set('quant_search_entity_node_bundles', ['event' => 'event'])
      ->save();
    $this->drupalGet('admin/config/development/quant/search/pages/create');
    $this->submitForm(['bundles[event]' => FALSE, 'bundles[article]' => TRUE, 'place_block' => FALSE], 'Create the search page');
    $this->assertSession()->pageTextContains('Re-index the content');
    $bundles = $this->config('quant_search.entities.settings')->get('quant_search_entity_node_bundles');
    $this->assertEqualsCanonicalizing(['event', 'article'], array_values(array_filter($bundles)));
  }

}
