<?php

namespace Drupal\Tests\quant_api\Functional;

use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Log in to Quant" flow end to end, against a fake dashboard.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class OAuthConnectTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'file', 'quant', 'quant_api', 'quant_api_oauth_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->writeSettings([
      'settings' => [
        'quant_api_dashboard_url' => (object) [
          'value' => $this->baseUrl . '/quant-test-dashboard',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalLogin($this->drupalCreateUser(['configure quant']));
  }

  /**
   * Logs in on the fake dashboard and picks a project.
   */
  protected function logInAndPick(string $project): void {
    $this->drupalGet('admin/config/development/quant/api');
    $this->submitForm([], 'Log in to Quant');
    $this->assertSession()->pageTextContains('Logged in as editor@example.com');
    $this->submitForm(['project' => 'test-org:' . $project], 'Use this project');
  }

  /**
   * The store, read fresh from the test site's database.
   */
  protected function store(): OAuthTokenStore {
    return \Drupal::service('quant_api.oauth_store');
  }

  /**
   * The login, the project choice, change project and log out.
   */
  public function testLoginChooseChangeLogout(): void {
    $this->logInAndPick('site-b');

    $authorize = \Drupal::state()->get('quant_api_oauth_test.authorize');
    $this->assertSame('drupal-quant', $authorize['client_id']);
    $this->assertSame('S256', $authorize['code_challenge_method']);
    $this->assertSame('full_access', $authorize['scope']);
    $this->assertStringEndsWith('/admin/config/development/quant/api/oauth/callback', $authorize['redirect_uri']);

    $session = $this->assertSession();
    $session->pageTextContains('This site now publishes to the Quant project site-b.');
    // One success message, not the same news twice.
    $session->pageTextNotContains('Connected to the Quant project site-b.');
    $session->pageTextContains('it can reach only that project');
    $session->linkExists('Change project');

    $config = $this->config('quant_api.settings');
    $this->assertSame('oauth', $config->get('auth_method'));
    $this->assertSame('test-org', $config->get('api_account'));
    $this->assertSame('site-b', $config->get('api_project'));
    $this->assertEmpty($config->get('api_token'));
    $this->assertSame('fake-write-token-site-b', $this->store()->get(OAuthTokenStore::WRITE_TOKEN));

    // The module limited the token before it read the write token.
    $requests = \Drupal::state()->get('quant_api_oauth_test.requests');
    $limit = array_search('POST /quant-test-dashboard/api/oauth/token/project', $requests, TRUE);
    $fetch = array_search('GET /quant-test-dashboard/api/v2/organizations/test-org/projects/site-b', $requests, TRUE);
    $this->assertNotFalse($limit);
    $this->assertGreaterThan($limit, $fetch);

    // Change project: a new login, the old project stays until the choice.
    $this->clickLink('Change project');
    $this->assertSame('site-b', $this->config('quant_api.settings')->get('api_project'));
    $this->submitForm(['project' => 'test-org:site-a'], 'Use this project');
    $session->pageTextContains('This site now publishes to the Quant project site-a.');
    $this->assertSame('fake-write-token-site-a', $this->store()->get(OAuthTokenStore::WRITE_TOKEN));

    \Drupal::state()->delete('quant_api_oauth_test.requests');
    $this->clickLink('Log out of Quant');
    $session->pageTextContains('This site is logged out of Quant.');
    // The login is ended in the dashboard too, not only deleted here.
    $this->assertContains('POST /quant-test-dashboard/api/oauth/revoke', \Drupal::state()->get('quant_api_oauth_test.requests', []));
    $this->assertSame('token', $this->config('quant_api.settings')->get('auth_method'));
    $this->assertSame([], array_filter($this->store()->getAll()));
    $session->buttonExists('Log in to Quant');
  }

  /**
   * A callback with a state from another login is refused.
   */
  public function testForgedStateIsRefused(): void {
    \Drupal::state()->set('quant_api_oauth_test.forge_state', 'attacker-state');
    $this->drupalGet('admin/config/development/quant/api');
    $this->submitForm([], 'Log in to Quant');
    $this->assertSession()->pageTextContains('The login expired or was started in another browser.');
    $this->assertNotContains('POST /quant-test-dashboard/oauth/token', \Drupal::state()->get('quant_api_oauth_test.requests', []));
    $this->assertSame('token', $this->config('quant_api.settings')->get('auth_method'));
  }

  /**
   * A login cancelled in the dashboard changes nothing.
   */
  public function testCancelledLogin(): void {
    \Drupal::state()->set('quant_api_oauth_test.deny', TRUE);
    $this->drupalGet('admin/config/development/quant/api');
    $this->submitForm([], 'Log in to Quant');
    $this->assertSession()->pageTextContains('The login was cancelled in the Quant dashboard.');
    $this->assertNull($this->store()->get(OAuthTokenStore::ACCESS_TOKEN));
  }

  /**
   * The connect route needs its CSRF token.
   */
  public function testConnectNeedsCsrfToken(): void {
    $this->drupalGet('admin/config/development/quant/api/oauth/connect');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('admin/config/development/quant/api/oauth/disconnect');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The routes need the "configure quant" permission.
   */
  public function testRoutesNeedPermission(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $paths = [
      'admin/config/development/quant/api',
      'admin/config/development/quant/api/oauth/callback',
      'admin/config/development/quant/api/oauth/project',
    ];
    foreach ($paths as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

  /**
   * A multi-domain site sees why it uses token connect.
   */
  public function testMultiDomainSiteUsesTokenConnect(): void {
    \Drupal::service('config.storage')->write('domain.config.example_com.quant_api.settings', ['api_project' => 'example']);
    $this->drupalGet('admin/config/development/quant/api');
    $this->assertSession()->pageTextContains('Log in to Quant supports one project per site for now');
    $this->assertSession()->buttonExists('Log in to Quant');
    $this->assertSession()->elementAttributeExists('css', 'input[value="Log in to Quant"]', 'disabled');
  }

  /**
   * Token connect still works, and QuantGov sets the QuantGov endpoint.
   */
  public function testTokenConnectAndPlatform(): void {
    $this->drupalGet('admin/config/development/quant/api');
    $this->submitForm([
      'platform' => 'quantgov',
      'api_account' => 'test-org',
      'api_project' => 'site-c',
      'api_token' => 'typed-token',
    ], 'Save configuration');
    $config = $this->config('quant_api.settings');
    $this->assertSame('quantgov', $config->get('platform'));
    $this->assertSame('https://api.quantgov.cloud', $config->get('api_endpoint'));
    $this->assertSame('token', $config->get('auth_method'));
    $this->assertSame('typed-token', $config->get('api_token'));

    // An empty token field keeps the saved token.
    $this->submitForm(['api_project' => 'site-d'], 'Save configuration');
    $this->assertSame('typed-token', $this->config('quant_api.settings')->get('api_token'));
    $this->assertSame('site-d', $this->config('quant_api.settings')->get('api_project'));
  }

  /**
   * A cancelled login on another platform leaves the connection as it was.
   */
  public function testCancelledLoginKeepsThePlatform(): void {
    $this->drupalGet('admin/config/development/quant/api');
    $this->submitForm(['api_account' => 'test-org', 'api_project' => 'site-c', 'api_token' => 'typed-token'], 'Save configuration');
    \Drupal::state()->set('quant_api_oauth_test.deny', TRUE);
    $this->submitForm(['platform' => 'quantgov'], 'Log in to Quant');
    $this->assertSession()->pageTextContains('The login was cancelled');
    $config = $this->config('quant_api.settings');
    $this->assertSame('quantcdn', $config->get('platform'));
    $this->assertSame('https://api.quantcdn.io', $config->get('api_endpoint'));
    $this->assertSame('typed-token', $config->get('api_token'));
  }

  /**
   * A completed login saves the chosen platform and fixes its endpoint.
   */
  public function testCompletedLoginSavesThePlatform(): void {
    $this->drupalGet('admin/config/development/quant/api');
    $this->submitForm(['platform' => 'quantgov'], 'Log in to Quant');
    $this->assertSame('quantcdn', $this->config('quant_api.settings')->get('platform'), 'Not saved before the project choice.');
    $this->submitForm(['project' => 'test-org:site-b'], 'Use this project');
    $config = $this->config('quant_api.settings');
    $this->assertSame('quantgov', $config->get('platform'));
    $this->assertSame('https://api.quantgov.cloud', $config->get('api_endpoint'));
    $this->assertSession()->elementAttributeExists('css', 'input[name="api_endpoint"]', 'disabled');
  }

  /**
   * A refused refresh is not shown as "Connected", and the status report says so.
   */
  public function testRefusedLoginIsShownToTheEditor(): void {
    $this->logInAndPick('site-b');
    \Drupal::service('quant_api.oauth_store')->set(OAuthTokenStore::REFUSAL, [
      'error' => 'invalid_grant',
      'at' => time(),
    ]);

    $this->drupalGet('admin/config/development/quant/api');
    $session = $this->assertSession();
    $session->pageTextNotContains('Connected to the Quant project site-b.');
    $session->elementTextContains('css', '[role="contentinfo"], .messages--warning, [data-drupal-messages]', 'Log in to Quant again');
    // The button says what the message asks for.
    $session->linkExists('Log in to Quant again');
    $session->linkNotExists('Change project');

    $this->drupalLogin($this->drupalCreateUser(['configure quant', 'administer site configuration']));
    $this->drupalGet('admin/reports/status');
    $session->pageTextContains('The Quant login needs renewing');
  }

  /**
   * A copied site says so and can be confirmed as the same site.
   */
  public function testCopiedSiteCanBeConfirmed(): void {
    $this->logInAndPick('site-b');
    $store = \Drupal::service('quant_api.oauth_store');
    $fingerprint = $store->get(OAuthTokenStore::FINGERPRINT);
    $fingerprint['database'] = hash('sha256', 'old database');
    $store->set(OAuthTokenStore::FINGERPRINT, $fingerprint);

    $this->drupalGet('admin/config/development/quant/api');
    $this->assertSession()->pageTextContains('This site looks like a copy of the connected site (different: database)');
    $this->submitForm([], 'This is the same site');
    $this->assertSession()->pageTextContains('Confirmed. This site publishes with its Quant login again.');
    $this->assertSession()->pageTextNotContains('This site looks like a copy');
  }

  /**
   * The settings.php endpoint is read-only and never shows its userinfo.
   */
  public function testEndpointOverrideIsReadOnly(): void {
    $this->writeSettings([
      'settings' => [
        'quant_api_endpoint_url' => (object) [
          'value' => 'https://rehearsal:placeholder-pass@uploads.stage.example',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalGet('admin/config/development/quant/api');
    $session = $this->assertSession();
    $session->elementAttributeExists('css', 'input[name="api_endpoint"]', 'disabled');
    $session->fieldValueEquals('api_endpoint', 'https://uploads.stage.example');
    $session->pageTextContains('Set in settings.php');
    $session->responseNotContains('placeholder-pass');
    $session->responseNotContains('rehearsal:');
  }

  /**
   * A push that failed after a save is shown on the next page view.
   */
  public function testFailedPushIsShownOnTheNextPageView(): void {
    \Drupal::service('quant_api.push_failures')->record('/news/a', 'publish', new \Exception('x'));
    $this->drupalGet('admin/config/development/quant/api');
    $session = $this->assertSession();
    $session->pageTextContains('1 change did not reach Quant');
    $session->pageTextContains('/news/a');

    // Another editor of the same site sees it too: it is per site.
    $this->drupalLogin($this->drupalCreateUser(['access content overview']));
    $this->drupalGet('admin/content');
    $session->pageTextContains('1 change did not reach Quant');

    // A visitor does not.
    $this->drupalLogout();
    $this->drupalGet('user/login');
    $session->pageTextNotContains('did not reach Quant');
  }

  /**
   * A connected site shows its saved platform, so a save keeps the login.
   *
   * With a dashboard override outside quantgov.cloud (a local QuantGov
   * dashboard), the radio used to default to QuantCDN while the saved
   * platform was QuantGov. Saving the form for another reason then logged
   * the site out.
   */
  public function testSaveKeepsTheLoginWithAnOverrideOnAnotherHost(): void {
    $this->logInAndPick('site-b');
    $this->config('quant_api.settings')->set('platform', 'quantgov')->save();

    $this->drupalGet('admin/config/development/quant/api');
    $this->assertSession()->checkboxChecked('edit-platform-quantgov');
    $this->submitForm([], 'Save configuration');
    $this->assertSession()->pageTextNotContains('The platform changed');
    $this->assertSame('oauth', $this->config('quant_api.settings')->get('auth_method'));
  }

  /**
   * A QuantGov dashboard in settings.php makes QuantGov the starting platform.
   */
  public function testDashboardOverrideSetsTheDefaultPlatform(): void {
    $this->writeSettings([
      'settings' => [
        'quant_api_dashboard_url' => (object) [
          'value' => 'https://dash.stage.quantgov.cloud',
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalGet('admin/config/development/quant/api');
    $this->assertSession()->checkboxChecked('edit-platform-quantgov');
  }

}
