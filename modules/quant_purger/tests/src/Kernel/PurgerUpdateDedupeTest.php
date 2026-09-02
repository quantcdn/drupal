<?php

namespace Drupal\Tests\quant_purger\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures the domain update survives the data a real site already holds.
 *
 * The table never had a unique key, so a site can hold several rows for the
 * same URL. Adding the key on top of those fails with an integrity constraint
 * and leaves the update half applied, mid deploy. The rows are merged first,
 * combining their tags: a tag dropped here is a page that stops being purged
 * when its content changes.
 *
 * @group quant_purger
 */
#[RunTestsInSeparateProcesses]
class PurgerUpdateDedupeTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    $this->installSchema('quant_purger', ['purge_queuer_quant']);

    // Return the table to its pre-update shape.
    $schema = $this->container->get('database')->schema();
    if ($schema->indexExists('purge_queuer_quant', 'url_domain')) {
      $schema->dropUniqueKey('purge_queuer_quant', 'url_domain');
    }
    if ($schema->fieldExists('purge_queuer_quant', 'domain')) {
      $schema->dropField('purge_queuer_quant', 'domain');
    }

    require_once $this->root . '/' . \Drupal::service('extension.list.module')->getPath('quant_purger') . '/quant_purger.install';
  }

  /**
   * Writes a pre-update row.
   */
  protected function seedRow(string $url, string $tags) : void {
    $this->container->get('database')->insert('purge_queuer_quant')
      ->fields(['url' => $url, 'tags' => $tags])
      ->execute();
  }

  /**
   * Runs the update to completion, as the update system would.
   *
   * @return array
   *   The sandbox, carrying the merged count.
   */
  protected function runUpdate() : array {
    $sandbox = [];
    $passes = 0;

    do {
      quant_purger_update_9103($sandbox);
      $passes++;
    } while (($sandbox['#finished'] ?? 1) < 1 && $passes < 100);

    $this->assertLessThan(100, $passes, 'The update terminated.');

    return $sandbox;
  }

  /**
   * Duplicate rows are merged and the unique key is added.
   */
  public function testDuplicatesAreMergedNotRejected() {
    $this->seedRow('/about', ';node:1;');
    $this->seedRow('/about', ';node:2;');
    $this->seedRow('/about', ';node:3;');
    $this->seedRow('/contact', ';node:4;');

    $sandbox = $this->runUpdate();

    $database = $this->container->get('database');
    $this->assertEquals(2, $database->select('purge_queuer_quant')->countQuery()->execute()->fetchField());
    $this->assertEquals(2, $sandbox['merged']);
    $this->assertTrue($database->schema()->indexExists('purge_queuer_quant', 'url_domain'));
  }

  /**
   * No tag is lost when rows are merged.
   *
   * A dropped tag is a page that stops being purged when its content changes,
   * which is silent until someone notices stale content.
   */
  public function testMergedRowsKeepEveryTag() {
    $this->seedRow('/about', ';node:1;');
    $this->seedRow('/about', ';node:2;node:3;');
    $this->seedRow('/about', ';node:4;');

    $this->runUpdate();

    $tags = $this->container->get('database')->select('purge_queuer_quant', 'q')
      ->fields('q', ['tags'])
      ->condition('url', '/about')
      ->execute()
      ->fetchField();

    foreach (['node:1', 'node:2', 'node:3', 'node:4'] as $tag) {
      $this->assertStringContainsString($tag, $tags, "Tag $tag survived the merge.");
    }
  }

  /**
   * A table with no duplicates is upgraded without touching its rows.
   */
  public function testCleanTableIsUnchanged() {
    $this->seedRow('/a', ';node:1;');
    $this->seedRow('/b', ';node:2;');

    $sandbox = $this->runUpdate();

    $this->assertEquals(0, $sandbox['merged']);
    $this->assertEquals(2, $this->container->get('database')
      ->select('purge_queuer_quant')->countQuery()->execute()->fetchField());
  }

  /**
   * Existing rows land in the empty domain, which is single-site behaviour.
   */
  public function testExistingRowsGetTheEmptyDomain() {
    $this->seedRow('/a', ';node:1;');

    $this->runUpdate();

    $domain = $this->container->get('database')->select('purge_queuer_quant', 'q')
      ->fields('q', ['domain'])
      ->condition('url', '/a')
      ->execute()
      ->fetchField();

    $this->assertSame('', $domain);
  }

}
