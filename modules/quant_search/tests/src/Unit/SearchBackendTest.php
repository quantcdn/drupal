<?php

namespace Drupal\Tests\quant_search\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\quant_search\SearchBackend;
use Drupal\Tests\UnitTestCase;

/**
 * Resolves Algolia on QuantCDN and Typesense on QuantGov.
 *
 * @coversDefaultClass \Drupal\quant_search\SearchBackend
 *
 * @group quant_search
 */
class SearchBackendTest extends UnitTestCase {

  /**
   * The logger channel double.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * Builds the service with the given API responses.
   *
   * @param mixed $project
   *   What QuantClient::project() returns.
   * @param mixed $stats
   *   What QuantClient::search() returns.
   * @param string|null $endpoint
   *   The configured Typesense endpoint.
   * @param \Drupal\Core\Cache\CacheBackendInterface|null $cache
   *   The cache backend.
   *
   * @return \Drupal\quant_search\SearchBackend
   *   The service under test.
   */
  protected function backend($project, $stats = NULL, $endpoint = NULL, ?CacheBackendInterface $cache = NULL) : SearchBackend {
    $client = $this->createMock(QuantClientInterface::class);
    $client->method('project')->willReturn($project);
    $client->method('search')->willReturn($stats);

    $api = $this->createMock(ImmutableConfig::class);
    $api->method('get')->willReturnMap([
      ['api_endpoint', 'https://api.quantgov.cloud'],
      ['api_account', 'org'],
      ['api_project', 'proj'],
    ]);
    $search = $this->createMock(ImmutableConfig::class);
    $search->method('get')->with('typesense_endpoint')->willReturn($endpoint);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnMap([
      ['quant_api.settings', $api],
      ['quant_search.settings', $search],
    ]);

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $loggers = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggers->method('get')->willReturn($this->logger);

    $time = $this->createMock('Drupal\Component\Datetime\TimeInterface');
    $time->method('getRequestTime')->willReturn(time());

    return new SearchBackend($client, $factory, $cache ?? new MemoryBackend($time), $loggers);
  }

  /**
   * A project record as /v1/ping returns it.
   */
  protected function project(array $config) {
    return json_decode(json_encode(['config' => $config]));
  }

  /**
   * @covers ::get
   */
  public function testAlgoliaFromTheProjectRecord() {
    $backend = $this->backend($this->project([
      'search_enabled' => TRUE,
      'search_index' => [
        'algolia_application_id' => 'APP',
        'algolia_read_key' => 'KEY',
        'algolia_index' => 'org_proj',
      ],
    ]))->get();

    $this->assertSame('algolia', $backend['backend']);
    $this->assertSame(['APP', 'KEY', 'org_proj', '', ''], [
      $backend['app_id'], $backend['read_key'], $backend['index'], $backend['endpoint'], $backend['sort_by'],
    ]);
    $this->assertSame(['quant_search/algolia-page'], SearchBackend::libraries($backend, 'page'));
  }

  /**
   * @covers ::get
   */
  public function testTypesenseOnQuantGov() {
    $stats = json_decode(json_encode([
      'readOnlyKey' => ['value' => 'TSKEY', 'collections' => ['org_proj']],
      'index' => ['name' => 'org_proj'],
      'settings' => ['attributesForFaceting' => ['url', 'content_type', 'tags_en', 'tags_en']],
    ]));
    $service = $this->backend($this->project([
      'search_enabled' => TRUE,
      'platform_mode' => 'aws',
      'search_index' => [
        'algolia_application_id' => 'STALE',
        'algolia_read_key' => 'STALE',
        'algolia_index' => 'org_proj',
      ],
      'search_config' => ['algolia' => ['custom_ranking' => ['desc(date)']]],
    ]), $stats, 'https://search.stage.quantgov.cloud/');

    $backend = $service->get();

    $this->assertSame('typesense', $backend['backend']);
    $this->assertSame(['', 'TSKEY', 'org_proj'], [$backend['app_id'], $backend['read_key'], $backend['index']]);
    $this->assertSame('https://search.stage.quantgov.cloud', $backend['endpoint']);
    $this->assertSame('_text_match:desc,date:desc', $backend['sort_by']);
    $this->assertSame(['url', 'content_type', 'tags_en'], $backend['facets_declared']);
    $this->assertSame(['quant_search/typesense-autocomplete'], SearchBackend::libraries($backend, 'autocomplete'));

    $settings = SearchBackend::clientSettings($backend);
    $this->assertSame('org_proj', $settings['algolia_index'], 'legacy key carries the collection');
    $this->assertSame('TSKEY', $settings['read_key']);
  }

  /**
   * @covers ::get
   */
  public function testTheDefaultEndpointAndTheIndexNameFallback() {
    $backend = $this->backend(
      $this->project(['search_enabled' => TRUE, 'platform_mode' => 'aws']),
      json_decode(json_encode(['readOnlyKey' => ['value' => 'K'], 'index' => ['name' => 'from_index']]))
    )->get();

    $this->assertSame('from_index', $backend['index']);
    $this->assertSame('https://search.quantgov.cloud', $backend['endpoint']);
    $this->assertSame([], $backend['facets_declared']);
  }

  /**
   * @covers ::get
   */
  public function testSearchIsUnavailableWhenDisabledOrUnreachable() {
    $this->assertFalse($this->backend($this->project(['search_enabled' => FALSE]))->get());
    $this->assertFalse($this->backend(FALSE)->get());
  }

  /**
   * @covers ::get
   */
  public function testAnIncompleteBackendIsNotCached() {
    $cache = new MemoryBackend($this->createMock('Drupal\Component\Datetime\TimeInterface'));
    $service = $this->backend($this->project(['search_enabled' => TRUE, 'platform_mode' => 'aws']), FALSE, NULL, $cache);

    $this->assertSame('', $service->get()['read_key']);
    $this->assertFalse($cache->get('quant_search:backend:' . md5('https://api.quantgov.cloud|org|proj')));
  }

  /**
   * @covers ::buildFilters
   */
  public function testFiltersUseTheBackendSyntax() {
    $this->assertSame(
      "(lang_code:'en' OR lang_code:'fr') AND (content_type:'article') AND tags_en:'News'",
      SearchBackend::buildFilters(['en', 'fr'], ['article' => 'article', 'page' => 0], "tags_en:'News'", 'algolia')
    );
    $this->assertSame(
      '(lang_code:=`en` || lang_code:=`fr`) && (content_type:=`article`) && tags_en:=`News`',
      SearchBackend::buildFilters(['en', 'fr'], ['article' => 'article', 'page' => 0], 'tags_en:=`News`', 'typesense')
    );
    $this->assertSame('', SearchBackend::buildFilters([], [], NULL, 'typesense'));
    $this->assertSame('(content_type:=`ok`)', SearchBackend::buildFilters([], ['ok', 'bad`value'], '', 'typesense'));
  }

  /**
   * @covers ::sortBy
   */
  public function testSortByKeepsRelevanceFirst() {
    $this->assertSame('', SearchBackend::sortBy([]));
    $this->assertSame('', SearchBackend::sortBy(['title', 'asc(bad field)']));
    $this->assertSame('_text_match:desc,a:asc,b:desc', SearchBackend::sortBy(['asc(a)', ' desc(b) ', 'asc(c)']));
  }

  /**
   * @covers ::declaredFacets
   */
  public function testUndeclaredFacetsAreDroppedOnlyWhenTheListIsKnown() {
    $service = $this->backend(FALSE);
    $facets = [
      'tags_en_0' => ['facet_key' => 'tags_en'],
      'ghost_en_1' => ['facet_key' => 'ghost_en'],
    ];

    $this->assertSame($facets, $service->declaredFacets($facets, ['facets_declared' => []]));

    $this->logger->expects($this->once())->method('warning');
    $this->assertSame(['tags_en_0' => ['facet_key' => 'tags_en']], $service->declaredFacets($facets, ['facets_declared' => ['tags_en']]));
  }

}
