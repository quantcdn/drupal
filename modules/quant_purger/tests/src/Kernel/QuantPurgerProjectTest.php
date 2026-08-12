<?php

namespace Drupal\Tests\quant_purger\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_purger\Plugin\Purge\Queuer\QuantPurger;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures a tag invalidation queues work for the project that owns it.
 *
 * A page shown on several domains has to be purged on each of them, and each
 * domain publishes to its own project. Stamping every item with the project
 * of whichever domain happened to trigger the invalidation would refresh one
 * client and leave the rest stale.
 *
 * @coversDefaultClass \Drupal\quant_purger\Plugin\Purge\Queuer\QuantPurger
 *
 * @group quant_purger
 */
#[RunTestsInSeparateProcesses]
class QuantPurgerProjectTest extends KernelTestBase {

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
    $this->installConfig(['quant_api', 'quant_purger']);

    \Drupal::configFactory()->getEditable('quant_api.settings')
      ->set('api_project', 'base-project')
      ->save();
  }

  /**
   * Calls the plugin's project resolver for a given domain.
   *
   * @param string $domainId
   *   The domain id, empty for a single-domain site.
   *
   * @return string|null
   *   The resolved project.
   */
  protected function resolve(string $domainId) {
    $plugin = new QuantPurger();
    $plugin->setContainer($this->container);

    $method = new \ReflectionMethod($plugin, 'getProjectForDomain');
    $method->setAccessible(TRUE);

    return $method->invoke($plugin, $domainId);
  }

  /**
   * Without a domain, the base configuration names the only project.
   *
   * @covers ::getProjectForDomain
   */
  public function testEmptyDomainResolvesBaseProject() {
    $this->assertEquals('base-project', $this->resolve(''));
  }

  /**
   * Without the Domain module, a domain id still falls back to the base.
   *
   * The override service is absent, so there is nowhere else to look. This
   * must not fatal.
   *
   * @covers ::getProjectForDomain
   */
  public function testUnknownDomainFallsBackToBaseProject() {
    $this->assertFalse(\Drupal::hasService('domain.config_factory_override'));
    $this->assertEquals('base-project', $this->resolve('clienta'));
  }

  /**
   * An unconfigured project reports NULL rather than an empty string.
   *
   * @covers ::getProjectForDomain
   */
  public function testUnconfiguredProjectIsNull() {
    \Drupal::configFactory()->getEditable('quant_api.settings')
      ->set('api_project', '')
      ->save();

    $this->assertNull($this->resolve(''));
  }

}
