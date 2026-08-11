<?php

namespace Drupal\Tests\quant_api\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Client\QuantClient;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;

/**
 * Ensures the client resolves its target project at call time.
 *
 * Sites that serve several domains from one Drupal instance give each domain
 * its own Quant project through config overrides. The active domain is not
 * known until it is negotiated, which happens after the container is built,
 * so credentials captured once at construction would pin every request to
 * the base project.
 *
 * @coversDefaultClass \Drupal\quant_api\Client\QuantClient
 *
 * @group quant_api
 */
class QuantClientProjectTest extends UnitTestCase {

  /**
   * Builds an immutable config double for one project.
   *
   * @param string $project
   *   The project machine name the config resolves to.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   The config double.
   */
  protected function config(string $project) : ImmutableConfig {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['api_account', 'test-org'],
      ['api_token', 'token-' . $project],
      ['api_project', $project],
      ['api_endpoint', 'http://test'],
      ['api_tls_disabled', FALSE],
    ]);
    return $config;
  }

  /**
   * Builds a client whose config factory yields the given configs in order.
   *
   * @param \Drupal\Core\Config\ImmutableConfig ...$configs
   *   The config objects to return on successive reads.
   *
   * @return \Drupal\quant_api\Client\QuantClient
   *   The client under test.
   */
  protected function client(ImmutableConfig ...$configs) : QuantClient {
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnOnConsecutiveCalls(...$configs);

    return new QuantClient(
      $this->createMock(Client::class),
      $factory,
      $this->createMock(LoggerChannelFactoryInterface::class)
    );
  }

  /**
   * The project is read from configuration on construction.
   *
   * @covers ::getProject
   */
  public function testProjectResolvesFromConfig() {
    $config = $this->config('project-a');
    // Two reads: one in the constructor, one in getProject().
    $client = $this->client($config, $config);

    $this->assertEquals('project-a', $client->getProject());
  }

  /**
   * The project follows a configuration change after construction.
   *
   * This is the multi-domain case: the container is built before the domain
   * is negotiated, so the project the constructor saw is not the project the
   * request must publish to.
   *
   * @covers ::getProject
   * @covers ::refreshCredentials
   */
  public function testProjectFollowsDomainSwitch() {
    $base = $this->config('base-project');
    $overridden = $this->config('project-client-a');

    // Read one is the constructor, before the domain is negotiated. Read two
    // is the first getProject(). Read three is after the switch.
    $client = $this->client($base, $base, $overridden);

    $this->assertEquals('base-project', $client->getProject());
    $this->assertEquals('project-client-a', $client->getProject());
  }

  /**
   * An unconfigured project reports NULL rather than an empty string.
   *
   * @covers ::getProject
   */
  public function testUnconfiguredProjectIsNull() {
    $empty = $this->createMock(ImmutableConfig::class);
    $empty->method('get')->willReturn('');

    $this->assertNull($this->client($empty, $empty)->getProject());
  }

}
