<?php

namespace Drupal\Tests\quant_api\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_api\OAuth\Platform;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The OAuth protocol and refresh rules of "Log in to Quant".
 *
 * @coversDefaultClass \Drupal\quant_api\OAuth\AuthService
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class OAuthAuthServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'quant_api'];

  /**
   * The canned dashboard answers.
   */
  protected MockHandler $mock;

  /**
   * The requests the module sent.
   */
  protected array $history = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['quant_api']);
    $this->mock = new MockHandler();
    $stack = HandlerStack::create($this->mock);
    $stack->push(Middleware::history($this->history));
    // Set before the OAuth services are first built, so they get this client.
    $this->container->set('http_client', new Client(['handler' => $stack]));
  }

  /**
   * The store.
   */
  protected function store(): OAuthTokenStore {
    return $this->container->get('quant_api.oauth_store');
  }

  /**
   * The service under test.
   *
   * @return \Drupal\quant_api\OAuth\AuthService
   *   The service.
   */
  protected function auth() {
    return $this->container->get('quant_api.oauth');
  }

  /**
   * Stores a connection whose access token expires in $expires_in seconds.
   */
  protected function connect(int $expires_in, array $extra = []): void {
    $this->auth()->saveConnection([
      'access_token' => 'qca_old',
      'refresh_token' => 'qcr_old',
      'expires_in' => $expires_in,
    ], 'write-token', ['name' => 'Editor', 'email' => 'editor@example.com']);
    if ($extra) {
      $this->store()->setMany($extra);
    }
  }

  /**
   * A JSON response.
   */
  protected static function json(int $status, array $body): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
  }

  /**
   * A successful refresh answer with a rotated pair.
   */
  protected static function rotated(): Response {
    return self::json(200, ['access_token' => 'qca_new', 'refresh_token' => 'qcr_new', 'expires_in' => 7776000]);
  }

  /**
   * The RFC 7636 example: S256 of the example verifier.
   *
   * @covers ::computePkceChallenge
   * @covers ::generatePkceVerifier
   */
  public function testPkce(): void {
    $this->assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $this->auth()->computePkceChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'));
    $verifier = $this->auth()->generatePkceVerifier();
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $verifier);
  }

  /**
   * The authorize URL names the client, the scope and an S256 challenge.
   *
   * @covers ::getAuthorizationUrl
   */
  public function testAuthorizationUrlPerPlatform(): void {
    $url = $this->auth()->getAuthorizationUrl('st', 'https://site.example/cb', 'ch');
    $this->assertStringStartsWith('https://dashboard.quantcdn.io/oauth/authorize?', $url);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame([
      'client_id' => Platform::CLIENT_ID,
      'response_type' => 'code',
      'redirect_uri' => 'https://site.example/cb',
      'state' => 'st',
      'scope' => 'full_access',
      'code_challenge' => 'ch',
      'code_challenge_method' => 'S256',
    ], $query);

    $this->config('quant_api.settings')->set('platform', Platform::QUANTGOV)->save();
    $this->assertStringStartsWith('https://dash.quantgov.cloud/oauth/authorize?', $this->auth()->getAuthorizationUrl('st', 'https://site.example/cb', 'ch'));
  }

  /**
   * A token far from expiry is used as it is, with no call.
   *
   * @covers ::getValidAccessToken
   */
  public function testFreshTokenNeedsNoRefresh(): void {
    $this->connect(86400 * 60);
    $this->assertSame('qca_old', $this->auth()->getValidAccessToken());
    $this->assertCount(0, $this->history);
  }

  /**
   * An expiring token is refreshed and the rotated pair is saved.
   *
   * @covers ::getValidAccessToken
   */
  public function testExpiringTokenIsRefreshedAndRotated(): void {
    $this->connect(30);
    $this->mock->append(self::rotated());

    $this->assertSame('qca_new', $this->auth()->getValidAccessToken());
    $this->assertSame('qcr_new', $this->store()->get(OAuthTokenStore::REFRESH_TOKEN));
    $this->assertNull($this->store()->get(OAuthTokenStore::PENDING));
    $this->assertSame('write-token', $this->store()->get(OAuthTokenStore::WRITE_TOKEN));
    parse_str((string) $this->history[0]['request']->getBody(), $sent);
    $this->assertSame([
      'grant_type' => 'refresh_token',
      'refresh_token' => 'qcr_old',
      'client_id' => 'drupal-quant',
    ], $sent);
  }

  /**
   * A final refusal stops further refresh attempts and keeps the tokens.
   *
   * @covers ::getValidAccessToken
   * @covers ::getReconnectReason
   */
  public function testRefusalIsFinal(): void {
    $this->connect(30);
    $this->mock->append(self::json(400, [
      'error' => 'invalid_grant',
      'error_description' => 'Refresh token revoked',
    ]));

    $this->assertSame('qca_old', $this->auth()->getValidAccessToken());
    $this->assertSame('refused', $this->auth()->getReconnectReason());
    $this->assertSame('qca_old', $this->auth()->getValidAccessToken());
    $this->assertCount(1, $this->history, 'No second refresh after a refusal.');
    $this->assertSame('write-token', $this->store()->get(OAuthTokenStore::WRITE_TOKEN));
  }

  /**
   * A peer's "just rotated" answer backs off and keeps the stored pair.
   *
   * @covers ::getValidAccessToken
   */
  public function testJustRotatedBacksOff(): void {
    $this->connect(30);
    $this->mock->append(self::json(400, [
      'error' => 'invalid_grant',
      'error_description' => 'Refresh token was just rotated',
    ]));

    $this->auth()->getValidAccessToken();
    $this->assertNull($this->auth()->getReconnectReason());
    $this->assertGreaterThan(time(), $this->store()->get(OAuthTokenStore::RETRY_AFTER));
    $this->auth()->getValidAccessToken();
    $this->assertCount(1, $this->history, 'The back-off holds the next request.');
  }

  /**
   * No answer leaves a pending marker; the same token is retried in the window.
   *
   * @covers ::getValidAccessToken
   */
  public function testLostAnswerIsRetriedInsideThePortalWindow(): void {
    $this->connect(30);
    $this->mock->append(new ConnectException('timeout', new Request('POST', 'https://dashboard.quantcdn.io/oauth/token')));
    $this->auth()->getValidAccessToken();
    $pending = $this->store()->get(OAuthTokenStore::PENDING);
    $this->assertSame(hash('sha256', 'qcr_old'), $pending['token']);

    // Too early: the portal answers "just rotated" before 45 s.
    $this->auth()->getValidAccessToken();
    $this->assertCount(1, $this->history);

    // Inside the recovery window: the same refresh token is sent again.
    $pending['since'] = time() - 60;
    $this->store()->set(OAuthTokenStore::PENDING, $pending);
    $this->mock->append(self::rotated());
    $this->assertSame('qca_new', $this->auth()->getValidAccessToken());
    parse_str((string) $this->history[1]['request']->getBody(), $sent);
    $this->assertSame('qcr_old', $sent['refresh_token']);
  }

  /**
   * A database copy does not refresh the original site's tokens.
   *
   * @covers ::getValidAccessToken
   * @covers ::getReconnectReason
   */
  public function testCopiedDatabaseDoesNotRefresh(): void {
    $this->connect(30);
    $fingerprint = $this->store()->get(OAuthTokenStore::FINGERPRINT);
    $fingerprint['uuid'] = hash('sha256', 'another site');
    $this->store()->set(OAuthTokenStore::FINGERPRINT, $fingerprint);

    $this->assertSame('qca_old', $this->auth()->getValidAccessToken());
    $this->assertSame('other_site', $this->auth()->getReconnectReason());
    $this->assertCount(0, $this->history);
  }

  /**
   * Cron refreshes 30 days before expiry, a request only at the last minute.
   *
   * @covers ::refreshFromCron
   */
  public function testCronRefreshesEarly(): void {
    $this->connect(86400 * 20, [OAuthTokenStore::LIFETIME => 7776000]);
    $this->auth()->getValidAccessToken();
    $this->assertCount(0, $this->history);

    $this->mock->append(self::rotated());
    $this->auth()->refreshFromCron();
    $this->assertSame('qca_new', $this->store()->get(OAuthTokenStore::ACCESS_TOKEN));
  }

  /**
   * Disconnect deletes every stored value.
   *
   * @covers ::disconnect
   */
  public function testDisconnect(): void {
    $this->connect(86400);
    $this->auth()->disconnect();
    $this->assertSame([], array_filter($this->store()->getAll()));
  }

  /**
   * The write token: settings.php, then the OAuth connection, then config.
   *
   * @covers \Drupal\quant_api\Credentials::getToken
   */
  public function testCredentialOrder(): void {
    $credentials = $this->container->get('quant_api.credentials');
    $this->config('quant_api.settings')->set('api_token', 'typed-token')->save();
    $this->assertSame('typed-token', $credentials->getToken());

    $this->connect(86400);
    $this->assertSame('typed-token', $credentials->getToken(), 'The OAuth store is used only with auth_method oauth.');
    $this->config('quant_api.settings')->set('auth_method', 'oauth')->save();
    $this->assertSame('write-token', $credentials->getToken());

    $GLOBALS['config']['quant_api.settings']['api_token'] = 'settings-php-token';
    $this->container->get('config.factory')->reset('quant_api.settings');
    $this->assertSame('settings-php-token', $credentials->getToken());
    unset($GLOBALS['config']['quant_api.settings']);
  }

  /**
   * No token ever reaches exportable configuration.
   *
   * @covers ::saveConnection
   */
  public function testTokensStayOutOfConfig(): void {
    $this->connect(86400);
    $this->config('quant_api.settings')->set('auth_method', 'oauth')->save();
    $raw = json_encode($this->container->get('config.storage')->read('quant_api.settings'));
    foreach (['qca_old', 'qcr_old', 'write-token'] as $secret) {
      $this->assertStringNotContainsString($secret, $raw);
    }
  }

  /**
   * Sites from before 2.3 get the platform of their endpoint.
   *
   * @covers ::getPlatform
   */
  public function testUpdateHookSetsThePlatformFromTheEndpoint(): void {
    $this->config('quant_api.settings')
      ->clear('platform')
      ->clear('auth_method')
      ->set('api_endpoint', 'https://api.quantgov.cloud')
      ->save();
    \Drupal::moduleHandler()->loadInclude('quant_api', 'install');
    quant_api_update_11001();

    $config = $this->config('quant_api.settings');
    $this->assertSame('quantgov', $config->get('platform'));
    $this->assertSame('token', $config->get('auth_method'));
    $this->assertSame('https://dash.quantgov.cloud', $this->auth()->getDashboardUrl());
  }

  /**
   * A copied database publishes nothing with the original site's login.
   *
   * @covers \Drupal\quant_api\Credentials::getToken
   * @covers \Drupal\quant_api\Credentials::isCopiedSite
   */
  public function testCopiedSiteGetsNoToken(): void {
    $this->connect(86400);
    $this->config('quant_api.settings')->set('auth_method', 'oauth')->save();
    $credentials = $this->container->get('quant_api.credentials');
    $this->assertSame('write-token', $credentials->getToken());

    $fingerprint = $this->store()->get(OAuthTokenStore::FINGERPRINT);
    $fingerprint['database'] = hash('sha256', 'production database');
    $this->store()->set(OAuthTokenStore::FINGERPRINT, $fingerprint);
    $this->assertTrue($credentials->isCopiedSite());
    $this->assertNull($credentials->getToken());
    $this->assertFalse($credentials->hasToken());
  }

  /**
   * With a login, the endpoint is the platform's own host.
   *
   * @covers \Drupal\quant_api\Credentials::getEndpoint
   */
  public function testLoginFixesTheEndpoint(): void {
    $credentials = $this->container->get('quant_api.credentials');
    $this->config('quant_api.settings')->set('api_endpoint', 'https://collector.example')->save();
    $this->assertSame('https://collector.example', $credentials->getEndpoint(), 'Token connect keeps a custom endpoint.');

    $this->config('quant_api.settings')->set('auth_method', 'oauth')->set('platform', 'quantgov')->save();
    $this->assertSame('https://api.quantgov.cloud', $credentials->getEndpoint());
    $this->config('quant_api.settings')->set('platform', 'quantcdn')->save();
    $this->assertSame('https://api.quantcdn.io', $credentials->getEndpoint());
  }

  /**
   * A write token rotated in the dashboard is re-read with the login.
   *
   * @covers \Drupal\quant_api\OAuth\WriteTokenSync::syncIfDue
   */
  public function testWriteTokenResync(): void {
    $this->connect(86400 * 80);
    $this->config('quant_api.settings')->set('auth_method', 'oauth')
      ->set('api_account', 'test-org')->set('api_project', 'site-a')->save();
    $sync = $this->container->get('quant_api.write_token_sync');

    $this->mock->append(self::json(200, ['machine_name' => 'site-a', 'write_token' => 'rotated-token']));
    $this->assertTrue($sync->syncIfDue(FALSE));
    $this->assertSame('rotated-token', $this->store()->get(OAuthTokenStore::WRITE_TOKEN));
    $request = $this->history[0]['request'];
    $this->assertSame('/api/v2/organizations/test-org/projects/site-a', $request->getUri()->getPath());
    $this->assertSame('with_token=1', $request->getUri()->getQuery());
    $this->assertSame('Bearer qca_old', $request->getHeaderLine('Authorization'));

    // Within a day nothing is sent, unless the login was just refreshed.
    $this->assertNull($sync->syncIfDue(FALSE));
    $this->assertCount(1, $this->history);
    $this->mock->append(self::json(200, ['machine_name' => 'site-a', 'write_token' => 'rotated-token']));
    $this->assertFalse($sync->syncIfDue(TRUE));
  }

  /**
   * Cron reports whether it rotated the tokens.
   *
   * @covers ::refreshFromCron
   */
  public function testCronReportsTheRotation(): void {
    $this->connect(86400 * 80, [OAuthTokenStore::LIFETIME => 7776000]);
    $this->assertFalse($this->auth()->refreshFromCron());
    $this->store()->set(OAuthTokenStore::EXPIRES_AT, time() + 86400);
    $this->mock->append(self::rotated());
    $this->assertTrue($this->auth()->refreshFromCron());
  }

}
