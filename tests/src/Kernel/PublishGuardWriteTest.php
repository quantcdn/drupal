<?php

namespace Drupal\Tests\quant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant\PublishGuard;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures every write path consults the guard, not just the event path.
 *
 * Search records, facets and index clearing call the API client directly and
 * dispatch no event, so guarding the subscriber alone left them open. Index
 * clearing is destructive: on an unrecognised host it would wipe another
 * client's search index.
 *
 * @coversDefaultClass \Drupal\quant\PublishGuard
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class PublishGuardWriteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'views',
    'quant',
    'quant_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    $this->installConfig(['quant_api']);
  }

  /**
   * Without the Domain module nothing is refused.
   *
   * @covers ::refuses
   */
  public function testAllowsWhenDomainModuleAbsent() {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('domain'));
    $this->assertFalse(PublishGuard::refuses());
  }

  /**
   * Every write method on the client consults the guard.
   *
   * Asserted by reading the source rather than by exercising each one,
   * because the point is that a method added later is covered too. A new
   * write that skips the check fails this.
   *
   * @covers ::refuses
   */
  public function testEveryWriteMethodIsGuarded() {
    $path = \Drupal::service('extension.list.module')->getPath('quant_api');
    $source = file_get_contents($this->root . '/' . $path . '/src/Client/QuantClient.php');

    // Methods that change something in a Quant project.
    $writes = [
      'send',
      'sendRedirect',
      'sendFile',
      'unpublish',
      'sendSearchRecords',
      'clearSearchIndex',
      'addFacets',
    ];

    foreach ($writes as $method) {
      $start = strpos($source, 'public function ' . $method . '(');
      $this->assertNotFalse($start, "QuantClient::$method() exists.");

      // The guard should be among the first statements, before any request.
      $body = substr($source, $start, 500);
      $this->assertStringContainsString(
        'refusesWrite(',
        $body,
        "QuantClient::$method() consults the publish guard."
      );
    }
  }

  /**
   * Read-only methods are left alone.
   *
   * The settings form reports whether the connection works by calling
   * ping() and project(), so those must keep answering on any host.
   *
   * @covers ::refuses
   */
  public function testReadMethodsAreNotGuarded() {
    $path = \Drupal::service('extension.list.module')->getPath('quant_api');
    $source = file_get_contents($this->root . '/' . $path . '/src/Client/QuantClient.php');

    foreach (['ping', 'project', 'search', 'getUrlMeta'] as $method) {
      $start = strpos($source, 'public function ' . $method . '(');
      $body = substr($source, $start, 400);
      $this->assertStringNotContainsString(
        'refusesWrite(',
        $body,
        "QuantClient::$method() is a read and stays unguarded."
      );
    }
  }

}
