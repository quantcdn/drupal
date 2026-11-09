<?php

namespace Drupal\Tests\quant_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\quant_search\SearchBackend;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proposes search pages from Views shaped like a council site's listings.
 *
 * @coversDefaultClass \Drupal\quant_search\ViewSearchPageConverter
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class ViewSearchPageConverterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'file', 'taxonomy', 'views', 'token',
    'quant', 'quant_api', 'quant_search',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    Vocabulary::create(['vid' => 'topic', 'name' => 'Topic'])->save();
  }

  /**
   * A View with the given filters, sorts and pager on a page display.
   */
  protected function view(string $id, array $filters, array $sorts = [], array $extra = [], string $base = 'node_field_data'): void {
    // Views expects each handler to carry its own id.
    $filters = self::withIds($filters, $base);
    $sorts = self::withIds($sorts, $base);
    if (isset($extra['arguments'])) {
      $extra['arguments'] = self::withIds($extra['arguments'], $base);
    }
    View::create([
      'id' => $id,
      'label' => ucfirst($id),
      'base_table' => $base,
      'display' => [
        'default' => [
          'id' => 'default',
          'display_plugin' => 'default',
          'display_options' => [
            'title' => ucfirst($id),
            'filters' => $filters,
            'sorts' => $sorts,
            'pager' => ['type' => 'full', 'options' => ['items_per_page' => 12]],
          ] + $extra,
        ],
        'page_1' => [
          'id' => 'page_1',
          'display_plugin' => 'page',
          'display_options' => ['path' => $id],
        ],
      ],
    ])->save();
  }

  /**
   * Handlers with their id and table set.
   */
  protected static function withIds(array $handlers, string $base): array {
    foreach ($handlers as $key => $handler) {
      $handlers[$key] = $handler + ['id' => $key, 'table' => $base];
    }
    return $handlers;
  }

  /**
   * The converter.
   *
   * @return \Drupal\quant_search\ViewSearchPageConverter
   *   The service.
   */
  protected function converter() {
    return $this->container->get('quant_search.view_converter');
  }

  /**
   * News: a fixed type, an exposed topic, an exposed year list, a sort.
   *
   * @covers ::propose
   */
  public function testNewsListing(): void {
    $this->view('news', [
      'status' => ['field' => 'status', 'plugin_id' => 'boolean', 'value' => '1'],
      'type' => ['field' => 'type', 'plugin_id' => 'bundle', 'value' => ['article' => 'article']],
      'field_topic_target_id' => [
        'field' => 'field_topic_target_id',
        'plugin_id' => 'taxonomy_index_tid',
        'vid' => 'topic',
        'exposed' => TRUE,
        'expose' => ['label' => 'Topic'],
      ],
      'field_year_value' => [
        'field' => 'field_year_value',
        'plugin_id' => 'list_field',
        'exposed' => TRUE,
        'expose' => ['label' => 'Year'],
      ],
      'combine' => [
        'field' => 'combine',
        'plugin_id' => 'combine',
        'exposed' => TRUE,
        'expose' => ['label' => 'Keywords'],
      ],
    ], ['created' => ['field' => 'created', 'plugin_id' => 'date']]);

    $proposal = $this->converter()->propose('news', 'page_1');
    $this->assertSame('News', $proposal['values']['title']);
    $this->assertSame('news', $proposal['values']['route']);
    $this->assertSame(['article'], $proposal['values']['bundles']);
    $this->assertSame(['taxonomy:topic'], $proposal['values']['facets']);
    $this->assertTrue($proposal['values']['display_search']);
    $this->assertSame(12, $proposal['values']['per_page']);
    $notes = implode(' ', array_map(fn ($note) => strip_tags((string) $note), $proposal['notes']));
    $this->assertStringContainsString('The filter Year is not in the Quant search index', $notes);
    $this->assertStringContainsString('The sort by created is not converted', $notes);
    $this->assertSame(['news:page_1' => 'News: /news'], $this->converter()->candidates());
  }

  /**
   * A fixed term filter becomes a filter on the term names.
   *
   * @covers ::propose
   */
  public function testFixedTermsAndExposedTypeAndDates(): void {
    $water = Term::create(['vid' => 'topic', 'name' => 'Water quality']);
    $water->save();
    $this->view('alerts', [
      'tid' => [
        'field' => 'tid',
        'plugin_id' => 'taxonomy_index_tid',
        'vid' => 'topic',
        'value' => [$water->id() => $water->id()],
      ],
      'type' => ['field' => 'type', 'plugin_id' => 'bundle', 'exposed' => TRUE],
      'end' => ['field' => 'field_event_date_end_value', 'plugin_id' => 'datetime'],
    ], [], ['arguments' => ['nid' => ['field' => 'nid']]]);

    $proposal = $this->converter()->propose('alerts', 'page_1');
    // No project is connected here, so the filter uses the Algolia syntax.
    $this->assertSame("topic_en:'Water quality'", $proposal['values']['manual_filters']);
    $this->assertSame(
      '(topic_en:=`Water quality` || topic_en:=`Drought`)',
      SearchBackend::anyOf('topic_en', ['Water quality', 'Drought'], 'typesense'),
    );
    $this->assertSame(['content_type'], $proposal['values']['facets']);
    $this->assertSame([], $proposal['values']['bundles']);
    $notes = implode(' ', array_map(fn ($note) => strip_tags((string) $note), $proposal['notes']));
    $this->assertStringContainsString('The condition on field_event_date_end_value is not converted', $notes);
    $this->assertStringContainsString('Contextual filters are not converted', $notes);
  }

  /**
   * A View of users, and an unknown View, are not converted.
   *
   * @covers ::propose
   * @covers ::candidates
   */
  public function testOnlyContentViews(): void {
    $this->view('people', [], [], [], 'users_field_data');
    $this->assertSame([], $this->converter()->candidates());
    $this->assertStringContainsString('does not list content items', (string) $this->converter()->propose('people', 'page_1')['notes'][0]);
    $this->assertNull($this->converter()->propose('missing', 'page_1'));
  }

  /**
   * Operators: "none of", "all of", "is empty" are honoured or noted.
   *
   * @covers ::propose
   */
  public function testOperators(): void {
    foreach (['article' => 'Article', 'event' => 'Event', 'page' => 'Page'] as $type => $name) {
      NodeType::create(['type' => $type, 'name' => $name])->save();
    }
    $water = Term::create(['vid' => 'topic', 'name' => 'Water']);
    $water->save();
    $drought = Term::create(['vid' => 'topic', 'name' => 'Drought']);
    $drought->save();
    $tids = [$water->id() => $water->id(), $drought->id() => $drought->id()];
    $this->view('notpages', [
      'type' => self::filter('type', 'bundle', 'not in', ['page' => 'page']),
      'all' => self::filter('tid', 'taxonomy_index_tid', 'and', $tids),
      'none' => self::filter('tid', 'taxonomy_index_tid', 'not', [$drought->id() => $drought->id()]),
      'empty' => self::filter('tid', 'taxonomy_index_tid', 'empty', []),
    ]);

    $proposal = $this->converter()->propose('notpages', 'page_1');
    $this->assertEqualsCanonicalizing(['article', 'event'], $proposal['values']['bundles']);
    $this->assertSame("(topic_en:'Water' AND topic_en:'Drought') AND NOT topic_en:'Drought'", $proposal['values']['manual_filters']);
    $notes = implode(' ', array_map(fn ($note) => strip_tags((string) $note), $proposal['notes']));
    $this->assertStringContainsString('The condition "empty" on tid is not converted', $notes);

    $this->assertSame('topic_en:!=[`Water`,`Drought`]', SearchBackend::noneOf('topic_en', ['Water', 'Drought'], 'typesense'));
    $this->assertSame(
      '(topic_en:=`Water` && topic_en:=`Drought`)',
      SearchBackend::allOf('topic_en', ['Water', 'Drought'], 'typesense'),
    );
  }

  /**
   * A fixed filter definition for the topic vocabulary.
   */
  protected static function filter(string $field, string $plugin, string $operator, array $value): array {
    $filter = [
      'field' => $field,
      'plugin_id' => $plugin,
      'operator' => $operator,
      'value' => $value,
    ];
    // Only the term filter has a vocabulary setting.
    if ($plugin === 'taxonomy_index_tid') {
      $filter['vid'] = 'topic';
    }
    return $filter;
  }

}
