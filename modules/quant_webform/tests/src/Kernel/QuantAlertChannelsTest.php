<?php

namespace Drupal\Tests\quant_webform\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_webform\QuantAlertChannels;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reads the alert channels of the organisation from the fake dashboard.
 *
 * @coversDefaultClass \Drupal\quant_webform\QuantAlertChannels
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class QuantAlertChannelsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'file', 'node', 'taxonomy', 'views',
    'webform', 'quant', 'quant_api', 'quant_webform', 'quant_api_oauth_test', 'dblog',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['quant_api']);
    $this->installSchema('dblog', ['watchdog']);
    $this->setSetting('quant_api_dashboard_url', 'http://dashboard.test/quant-test-dashboard');
  }

  /**
   * The service under test.
   */
  protected function channels(): QuantAlertChannels {
    return $this->container->get('quant_webform.alert_channels');
  }

  /**
   * Connects the site with the fake dashboard's login.
   */
  protected function connect(): void {
    $this->config('quant_api.settings')
      ->set('auth_method', 'oauth')
      ->set('api_account', 'test-org')
      ->set('api_project', 'site-a')
      ->save();
    /** @var \Drupal\quant_api\OAuth\OAuthTokenStore $store */
    $store = $this->container->get('quant_api.oauth_store');
    $store->saveTokens([
      'access_token' => 'qca_fake_access',
      'refresh_token' => 'qcr_fake_refresh',
      'expires_in' => 7776000,
    ]);
    $store->set(OAuthTokenStore::WRITE_TOKEN, 'fake-write-token-site-a');
  }

  /**
   * The dashboard calls the fake recorded.
   */
  protected function requests(): array {
    return \Drupal::state()->get('quant_api_oauth_test.requests', []);
  }

  /**
   * A site with a project token only is not connected, and calls nothing.
   *
   * @covers ::load
   */
  public function testNotConnected(): void {
    $this->config('quant_api.settings')
      ->set('auth_method', 'token')
      ->set('api_account', 'test-org')
      ->set('api_project', 'site-a')
      ->save();
    $this->assertSame(['state' => 'not_connected', 'channels' => []], $this->channels()->load());
    $this->assertSame([], $this->requests());
  }

  /**
   * The channels of the organisation, with the invalid row dropped.
   *
   * @covers ::load
   */
  public function testOk(): void {
    $this->connect();
    \Drupal::state()->set('quant_api_oauth_test.alert_channels', [
      ['id' => 'ch_email1', 'name' => 'Enquiries', 'type' => 'email', 'hint' => 'e***@example.com'],
      ['id' => 'ch_slack1', 'name' => 'Web team', 'type' => 'slack', 'hint' => '#web'],
      ['id' => 'bad id!', 'name' => 'Broken', 'type' => 'email', 'hint' => ''],
      ['id' => str_repeat('a', 65), 'name' => 'Too long', 'type' => 'email', 'hint' => ''],
      'not a row',
      ['name' => 'No id', 'type' => 'email'],
      ['id' => 'ch_teams', 'name' => 'Teams', 'type' => 'teams'],
    ]);
    $this->assertSame([
      'state' => 'ok',
      'channels' => [
        ['id' => 'ch_email1', 'name' => 'Enquiries', 'type' => 'email', 'hint' => 'e***@example.com'],
        ['id' => 'ch_slack1', 'name' => 'Web team', 'type' => 'slack', 'hint' => '#web'],
        ['id' => 'ch_teams', 'name' => 'Teams', 'type' => 'teams', 'hint' => ''],
      ],
    ], $this->channels()->load());
    $this->assertSame(['GET /quant-test-dashboard/api/v3/organizations/test-org/projects/site-a/alert-channels'], $this->requests());
  }

  /**
   * A 403 or a 401 (a revoked token) asks for a new login.
   *
   * @covers ::load
   */
  public function testReconnect(): void {
    $this->connect();
    foreach ([403, 401] as $status) {
      \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', $status);
      $this->assertSame(['state' => 'reconnect', 'channels' => []], $this->channels()->load(), "Status $status");
    }
    // An expected state, so the log gets debug entries, not warnings.
    $this->assertSame([RfcLogLevel::DEBUG, RfcLogLevel::DEBUG], $this->logLevels());

    \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', 500);
    $this->channels()->load();
    $this->assertSame([RfcLogLevel::DEBUG, RfcLogLevel::DEBUG, RfcLogLevel::WARNING], $this->logLevels());
  }

  /**
   * The severities of the quant_api log entries, oldest first.
   */
  protected function logLevels(): array {
    return array_map('intval', \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['severity'])
      ->condition('type', 'quant_api')
      ->orderBy('wid')
      ->execute()
      ->fetchCol());
  }

  /**
   * Any other failure: the channels are not available now.
   *
   * @covers ::load
   */
  public function testUnavailable(): void {
    $this->connect();
    \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', 500);
    $this->assertSame(['state' => 'unavailable', 'channels' => []], $this->channels()->load());

    // No dashboard answer at all (the request fails): still no exception.
    \Drupal::state()->delete('quant_api_oauth_test.alert_channels_status');
    $this->config('quant_api.settings')->set('api_project', 'Not A Machine Name')->save();
    $this->assertSame(['state' => 'unavailable', 'channels' => []], $this->channels()->load());
  }

  /**
   * An OAuth site without a usable token must log in again.
   *
   * @covers ::load
   */
  public function testNoUsableToken(): void {
    $this->connect();
    $this->container->get('quant_api.oauth_store')->delete([OAuthTokenStore::ACCESS_TOKEN]);
    $this->assertSame(['state' => 'reconnect', 'channels' => []], $this->channels()->load());
    $this->assertSame([], $this->requests());
  }

}
