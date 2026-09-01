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
 * The Domain module changed where domain_config keeps overrides between its
 * 2.x and 3.x lines, and both support Drupal 11. Both are covered here. An
 * earlier version of this test asserted that the 3.x service was absent,
 * which recorded the resulting bug as expected behaviour rather than catching
 * it, so the storage is written directly and no service is named.
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
    $this->installConfig(['quant_api', 'quant_purger']);

    \Drupal::configFactory()->getEditable('quant_api.settings')
      ->set('api_project', 'base-project')
      ->save();
  }

  /**
   * Writes a project override the way Domain 2.x stores one.
   *
   * @param string $domainId
   *   The domain id.
   * @param string $project
   *   The project the domain publishes to.
   */
  protected function writeLegacyOverride(string $domainId, string $project) : void {
    // Written to storage rather than through the config factory: these are
    // ad-hoc names that carry no schema of their own, and the kernel test
    // schema checker rejects them. domain_config writes them the same way.
    \Drupal::service('config.storage')
      ->write('domain.config.' . $domainId . '.quant_api.settings', ['api_project' => $project]);
  }

  /**
   * Writes a project override the way Domain 3.x stores one.
   *
   * @param string $domainId
   *   The domain id.
   * @param string $project
   *   The project the domain publishes to.
   */
  protected function writeCollectionOverride(string $domainId, string $project) : void {
    \Drupal::service('config.storage')
      ->createCollection('domain.' . $domainId)
      ->write('quant_api.settings', ['api_project' => $project]);
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
   * An override stored the Domain 2.x way is found.
   *
   * @covers ::getProjectForDomain
   * @covers ::readDomainOverride
   */
  public function testResolvesDomain2xOverride() {
    $this->writeLegacyOverride('clientb', 'project-clientb');

    $this->assertEquals('project-clientb', $this->resolve('clientb'));
  }

  /**
   * An override stored the Domain 3.x way is found.
   *
   * @covers ::getProjectForDomain
   * @covers ::readDomainOverride
   */
  public function testResolvesDomain3xOverride() {
    $this->writeCollectionOverride('clientb', 'project-clientb');

    $this->assertEquals('project-clientb', $this->resolve('clientb'));
  }

  /**
   * Each domain resolves its own project, not its neighbour's.
   *
   * This is the case the queuer exists for: one invalidation touching a page
   * that several domains serve.
   *
   * @covers ::getProjectForDomain
   */
  public function testEachDomainResolvesItsOwnProject() {
    $this->writeCollectionOverride('clienta', 'project-clienta');
    $this->writeLegacyOverride('clientb', 'project-clientb');

    $this->assertEquals('project-clienta', $this->resolve('clienta'));
    $this->assertEquals('project-clientb', $this->resolve('clientb'));
  }

  /**
   * A domain with no override of its own uses the base project.
   *
   * @covers ::getProjectForDomain
   */
  public function testDomainWithoutOverrideUsesBaseProject() {
    $this->assertEquals('base-project', $this->resolve('clientc'));
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
   * An unconfigured project reports NULL rather than an empty string.
   *
   * @covers ::getProjectForDomain
   */
  public function testUnconfiguredProjectIsNull() {
    \Drupal::configFactory()->getEditable('quant_api.settings')
      ->set('api_project', '')
      ->save();

    $this->assertNull($this->resolve(''));
    $this->assertNull($this->resolve('clientc'));
  }

}
