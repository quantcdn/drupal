<?php

namespace Drupal\Tests\quant_purger\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures the traffic registry keeps each domain's paths apart.
 *
 * A site serving several domains from one Drupal instance has the same path
 * on every domain — /about belongs to every client. Recording those against
 * a bare path collapses them into one row, so invalidating a cache tag only
 * refreshes whichever domain registered it last and leaves the rest stale.
 *
 * @coversDefaultClass \Drupal\quant_purger\TrafficRegistry
 *
 * @group quant_purger
 */
#[RunTestsInSeparateProcesses]
class TrafficRegistryDomainTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'purge',
    'quant',
    'quant_api',
    'quant_purger',
    'taxonomy',
    'views',
    'field',
    'text',
    'filter',
    'node',
  ];

  /**
   * The registry under test.
   *
   * @var \Drupal\quant_purger\TrafficRegistryInterface
   */
  protected $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->installSchema('quant_purger', ['purge_queuer_quant']);
    $this->installConfig(['quant_purger']);

    $this->registry = $this->container->get('quant_purger.registry');
  }

  /**
   * Writes a registry row for a given domain.
   *
   * The registry resolves the domain itself, which needs the Domain module.
   * Writing directly keeps this test focused on the storage behaviour.
   *
   * @param string $url
   *   The path.
   * @param string $domain
   *   The domain id.
   * @param array $tags
   *   The cache tags.
   */
  protected function record(string $url, string $domain, array $tags) : void {
    $fields = [
      'url' => $url,
      'domain' => $domain,
      'tags' => ';' . implode(';', $tags) . ';',
    ];

    $this->container->get('database')->merge('purge_queuer_quant')
      ->insertFields($fields)
      ->updateFields($fields)
      ->keys(['url' => $url, 'domain' => $domain])
      ->execute();
  }

  /**
   * The same path on two domains is kept as two rows.
   *
   * @covers ::getPathsByDomain
   */
  public function testSamePathOnTwoDomainsIsNotCollapsed() {
    $this->record('/about', 'clienta', ['node:1']);
    $this->record('/about', 'clientb', ['node:1']);

    $byDomain = $this->registry->getPathsByDomain(['node:1']);

    $this->assertEqualsCanonicalizing(['clienta', 'clientb'], array_keys($byDomain));
    $this->assertEquals(['/about'], $byDomain['clienta']);
    $this->assertEquals(['/about'], $byDomain['clientb']);
  }

  /**
   * Only the domains serving a tag are returned.
   *
   * @covers ::getPathsByDomain
   */
  public function testUnrelatedDomainIsNotReturned() {
    $this->record('/about', 'clienta', ['node:1']);
    $this->record('/contact', 'clientb', ['node:2']);

    $byDomain = $this->registry->getPathsByDomain(['node:1']);

    $this->assertEquals(['clienta'], array_keys($byDomain));
  }

  /**
   * A path registered on one domain only purges that domain.
   *
   * @covers ::getPathsByDomain
   */
  public function testDistinctPathsPerDomain() {
    $this->record('/a', 'clienta', ['node:1']);
    $this->record('/b', 'clienta', ['node:1']);
    $this->record('/c', 'clientb', ['node:1']);

    $byDomain = $this->registry->getPathsByDomain(['node:1']);

    $this->assertEqualsCanonicalizing(['/a', '/b'], $byDomain['clienta']);
    $this->assertEquals(['/c'], $byDomain['clientb']);
  }

  /**
   * A site without the Domain module groups everything under one empty key.
   *
   * @covers ::getPathsByDomain
   */
  public function testSingleDomainSiteUsesEmptyKey() {
    $this->record('/about', '', ['node:1']);

    $byDomain = $this->registry->getPathsByDomain(['node:1']);

    $this->assertEquals([''], array_keys($byDomain));
    $this->assertEquals(['/about'], $byDomain['']);
  }

  /**
   * The flat path list still works and reports each path once.
   *
   * @covers ::getPaths
   */
  public function testGetPathsDeduplicatesAcrossDomains() {
    $this->record('/about', 'clienta', ['node:1']);
    $this->record('/about', 'clientb', ['node:1']);
    $this->record('/other', 'clientb', ['node:1']);

    $this->assertEqualsCanonicalizing(
      ['/about', '/other'],
      $this->registry->getPaths(['node:1'])
    );
  }

  /**
   * An unmatched tag returns nothing rather than everything.
   *
   * @covers ::getPathsByDomain
   */
  public function testUnmatchedTagReturnsNothing() {
    $this->record('/about', 'clienta', ['node:1']);

    $this->assertEquals([], $this->registry->getPathsByDomain(['node:999']));
  }

  /**
   * Adding a path records it against the active domain.
   *
   * Without the Domain module that is the empty string, and the row is
   * updated in place rather than duplicated.
   *
   * @covers ::add
   */
  public function testAddIsIdempotentForOneDomain() {
    $this->registry->add('/about', ['node:1']);
    $this->registry->add('/about', ['node:1', 'node:2']);

    $count = $this->container->get('database')
      ->select('purge_queuer_quant', 'q')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertEquals(1, $count);
    $this->assertEquals(['/about'], $this->registry->getPaths(['node:2']));
  }

}
