<?php

namespace Drupal\Tests\quant_webform\Functional;

use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_webform\QuantFormsMapper;
use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Quant Forms section of a Webform's settings.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class QuantFormsSettingsSectionTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node', 'file', 'webform', 'quant', 'quant_api', 'quant_webform', 'quant_webform_test', 'quant_api_oauth_test',
  ];

  /**
   * The form field of a channel row.
   */
  protected const ROW = 'third_party_settings[quant_webform][channels][list][%s][%s]';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Every dashboard call goes to the fake dashboard, never to Quant.
    $this->writeSettings([
      'settings' => [
        'quant_api_dashboard_url' => (object) [
          'value' => $this->baseUrl . '/quant-test-dashboard',
          'required' => TRUE,
        ],
      ],
    ]);
    Webform::create([
      'id' => 'contact_us',
      'title' => 'Contact us',
      'elements' => "email: { '#type': email, '#title': Email }",
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer webform', 'configure quant']));
  }

  /**
   * Refuses invalid input; saves valid input with the secrets outside config.
   */
  public function testValidateAndSave(): void {
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $session = $this->assertSession();
    $session->pageTextContains('Quant Forms');
    $session->checkboxNotChecked('third_party_settings[quant_webform][enabled]');
    $session->pageTextContains('Not available with Quant Forms');
    $session->elementAttributeExists('css', 'input[name="quant_webform_unsupported[captcha]"]', 'disabled');
    // A form that is off and has no channels does not read the channels.
    $session->pageTextContains('Alert channels');
    $session->pageTextContains('Tick "Take submissions through Quant Forms" and save to pick alert channels.');
    $session->fieldNotExists(sprintf(self::ROW, 'ch_email1', 'use'));

    $this->submitForm([
      'third_party_settings[quant_webform][enabled]' => TRUE,
      'third_party_settings[quant_webform][email][mode]' => 'custom',
      'third_party_settings[quant_webform][email][to]' => 'not an address',
      'quant_webform_secrets[slack_webhook]' => 'http://hooks.slack.com/services/x',
      'third_party_settings[quant_webform][protection][blocked_countries]' => 'Australia',
      'third_party_settings[quant_webform][after][confirmation_type]' => 'url',
      'third_party_settings[quant_webform][after][confirmation_url]' => 'javascript:alert(1)',
    ], 'Save');
    $session->pageTextContains('To: enter valid email addresses');
    $session->pageTextContains('Enter a Slack incoming webhook URL');
    $session->pageTextContains('AUSTRALIA is not a two-letter country code');
    $session->pageTextContains('Enter a path on this site');
    $this->assertFalse((bool) $this->webform()->getThirdPartySetting('quant_webform', 'enabled'));

    $this->submitForm([
      'third_party_settings[quant_webform][enabled]' => TRUE,
      'third_party_settings[quant_webform][email][mode]' => 'custom',
      'third_party_settings[quant_webform][email][to]' => 'a@example.com, b@example.com',
      'third_party_settings[quant_webform][email][from]' => 'web@example.com',
      'third_party_settings[quant_webform][email][subject]' => 'Enquiry from {{email}}',
      'quant_webform_secrets[slack_webhook]' => 'https://hooks.slack.com/services/T000/B000/abcd1234',
      'third_party_settings[quant_webform][protection][honeypot]' => TRUE,
      'third_party_settings[quant_webform][protection][blocked_countries]' => 'kp, ru',
      'third_party_settings[quant_webform][protection][spam_threshold]' => '3',
      'third_party_settings[quant_webform][after][confirmation_type]' => 'url',
      'third_party_settings[quant_webform][after][confirmation_url]' => '/thank-you',
    ], 'Save');
    $session->pageTextContains('Webform settings Contact us has been saved.');

    $settings = $this->webform()->getThirdPartySettings('quant_webform');
    $this->assertTrue($settings['enabled']);
    $this->assertSame('a@example.com, b@example.com', $settings['email']['to']);
    $this->assertSame(['KP', 'RU'], $settings['blocked_countries']);
    $this->assertSame('3', $settings['spam_threshold']);
    $this->assertSame(['type' => 'url', 'url' => '/thank-you'], $settings['confirmation']);
    $this->assertTrue($settings['honeypot']);
    $this->assertSame('https://hooks.slack.com/services/T000/B000/abcd1234', \Drupal::keyValue(QuantFormsMapper::SECRETS)->get('slack_webhook:contact_us'));
    $this->assertStringNotContainsString('hooks.slack.com', json_encode(\Drupal::config('webform.webform.contact_us')->getRawData()));

    // The saved webhook shows masked, and an empty field keeps it.
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $session->pageTextContains('Saved: ••••••••1234');
    $session->responseNotContains('B000/abcd1234');
    $this->submitForm([], 'Save');
    $this->assertSame('https://hooks.slack.com/services/T000/B000/abcd1234', \Drupal::keyValue(QuantFormsMapper::SECRETS)->get('slack_webhook:contact_us'));
  }

  /**
   * Secrets are stored only by a successful save; Remove works when unticked.
   */
  public function testSecretsFollowTheSave(): void {
    $kv = \Drupal::keyValue(QuantFormsMapper::SECRETS);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    // Another field is invalid: the valid webhook is not stored.
    $this->submitForm([
      'third_party_settings[quant_webform][enabled]' => TRUE,
      'third_party_settings[quant_webform][email][mode]' => 'custom',
      'third_party_settings[quant_webform][email][to]' => 'not an address',
      'quant_webform_secrets[slack_webhook]' => 'https://hooks.slack.com/services/T1/B1/keep5678',
    ], 'Save');
    $this->assertSession()->pageTextContains('To: enter valid email addresses');
    $this->assertNull($kv->get('slack_webhook:contact_us'));

    // reCAPTCHA needs its secret.
    $this->submitForm([
      'third_party_settings[quant_webform][enabled]' => TRUE,
      'third_party_settings[quant_webform][email][mode]' => 'handler',
      'third_party_settings[quant_webform][protection][recaptcha][enabled]' => TRUE,
      'third_party_settings[quant_webform][protection][recaptcha][site_key]' => 'site-key-0123456789abcdef',
    ], 'Save');
    $this->assertSession()->pageTextContains('Enter the reCAPTCHA v2 secret key.');

    // A save on an unticked form stores the webhook, and Remove deletes it.
    $this->submitForm([
      'third_party_settings[quant_webform][enabled]' => FALSE,
      'third_party_settings[quant_webform][protection][recaptcha][enabled]' => FALSE,
      'quant_webform_secrets[slack_webhook]' => 'https://hooks.slack.com/services/T1/B1/keep5678',
    ], 'Save');
    $this->assertSame('https://hooks.slack.com/services/T1/B1/keep5678', $kv->get('slack_webhook:contact_us'));
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->submitForm(['quant_webform_secrets[slack_webhook_remove]' => TRUE], 'Save');
    $this->assertNull($kv->get('slack_webhook:contact_us'));
  }

  /**
   * Connects the site with the fake dashboard's login.
   *
   * @param bool $enable
   *   Whether to tick "Take submissions through Quant Forms" on the form.
   */
  protected function connect(bool $enable = TRUE): void {
    if ($enable) {
      $this->webform()->setThirdPartySetting('quant_webform', 'enabled', TRUE)->save();
    }
    $this->config('quant_api.settings')
      ->set('auth_method', 'oauth')
      ->set('api_account', 'test-org')
      ->set('api_project', 'site-a')
      ->save();
    $store = \Drupal::service('quant_api.oauth_store');
    $store->saveTokens([
      'access_token' => 'qca_fake_access',
      'refresh_token' => 'qcr_fake_refresh',
      'expires_in' => 7776000,
    ]);
    $store->set(OAuthTokenStore::WRITE_TOKEN, 'fake-write-token-site-a');
  }

  /**
   * Stores channels on the form, as a save in the settings would.
   */
  protected function storeChannels(array $channels): void {
    $this->webform()->setThirdPartySetting('quant_webform', 'channels', $channels)->save();
  }

  /**
   * The stored channels of the form.
   */
  protected function storedChannels(): array {
    return $this->webform()->getThirdPartySetting('quant_webform', 'channels', []);
  }

  /**
   * Picks "to" and "cc" channels; the form webhook goes, the site one stays.
   */
  public function testPickerSavesChannels(): void {
    $this->connect();
    $kv = \Drupal::keyValue(QuantFormsMapper::SECRETS);
    $kv->set('slack_webhook:contact_us', 'https://hooks.slack.com/services/T1/B1/form1234');
    $kv->set('slack_webhook', 'https://hooks.slack.com/services/T1/B1/site1234');

    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $session = $this->assertSession();
    $session->pageTextContains('Alert channels');
    $session->pageTextContains('Enquiries');
    $session->pageTextContains('e***@example.com');
    $session->pageTextContains('Web team');
    $session->pageTextContains('the email recipients set in Drupal are not used');
    $session->fieldExists(sprintf(self::ROW, 'ch_email1', 'cc'));
    $session->fieldExists(sprintf(self::ROW, 'ch_slack1', 'use'));
    $session->fieldNotExists(sprintf(self::ROW, 'ch_slack1', 'cc'));
    $session->optionExists('third_party_settings[quant_webform][retention_days]', 'Keep forever');

    $this->submitForm([
      'third_party_settings[quant_webform][enabled]' => TRUE,
      sprintf(self::ROW, 'ch_email1', 'use') => TRUE,
      sprintf(self::ROW, 'ch_email2', 'use') => TRUE,
      sprintf(self::ROW, 'ch_email2', 'cc') => TRUE,
      sprintf(self::ROW, 'ch_slack1', 'use') => TRUE,
      'third_party_settings[quant_webform][retention_days]' => '365',
    ], 'Save');
    $session->pageTextContains('Webform settings Contact us has been saved.');
    $this->assertSame([
      ['id' => 'ch_email1', 'cc' => FALSE],
      ['id' => 'ch_email2', 'cc' => TRUE],
      ['id' => 'ch_slack1', 'cc' => FALSE],
    ], $this->storedChannels());
    $this->assertSame('365', $this->webform()->getThirdPartySetting('quant_webform', 'retention_days'));
    $this->assertNull($kv->get('slack_webhook:contact_us'));
    $this->assertSame('https://hooks.slack.com/services/T1/B1/site1234', $kv->get('slack_webhook'));
    // The hint is for display only.
    $this->assertStringNotContainsString('***', json_encode(\Drupal::config('webform.webform.contact_us')->getRawData()));

    // The picked channels show ticked.
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $session->checkboxChecked(sprintf(self::ROW, 'ch_email1', 'use'));
    $session->checkboxNotChecked(sprintf(self::ROW, 'ch_email1', 'cc'));
    $session->checkboxChecked(sprintf(self::ROW, 'ch_email2', 'cc'));
    $session->checkboxChecked(sprintf(self::ROW, 'ch_slack1', 'use'));
    $this->assertSame('365', $session->fieldExists('third_party_settings[quant_webform][retention_days]')->getValue());

    // Unticked, a channel goes; with no channel the form keeps its webhook.
    $this->submitForm([
      sprintf(self::ROW, 'ch_email1', 'use') => FALSE,
      sprintf(self::ROW, 'ch_email2', 'use') => FALSE,
      sprintf(self::ROW, 'ch_slack1', 'use') => FALSE,
      'quant_webform_secrets[slack_webhook]' => 'https://hooks.slack.com/services/T1/B1/again123',
    ], 'Save');
    $this->assertSame([], $this->storedChannels());
    $this->assertSame('https://hooks.slack.com/services/T1/B1/again123', $kv->get('slack_webhook:contact_us'));
  }

  /**
   * A form that is not ticked and has no channels calls no dashboard.
   */
  public function testNoCallWhenFormIsOff(): void {
    $this->connect(FALSE);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->pageTextContains('Tick "Take submissions through Quant Forms" and save to pick alert channels.');
    $this->assertSession()->fieldNotExists(sprintf(self::ROW, 'ch_email1', 'use'));
    $this->submitForm([], 'Save');
    $this->assertSame([], \Drupal::state()->get('quant_api_oauth_test.requests', []));

    // Ticked and saved: the next page lists the channels.
    $this->submitForm(['third_party_settings[quant_webform][enabled]' => TRUE], 'Save');
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->fieldExists(sprintf(self::ROW, 'ch_email1', 'use'));
  }

  /**
   * A listed Slack channel never shows a Cc box, also with a stored cc.
   */
  public function testNoCcBoxOnSlack(): void {
    $this->connect();
    $this->storeChannels([['id' => 'ch_slack1', 'cc' => TRUE]]);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->checkboxChecked(sprintf(self::ROW, 'ch_slack1', 'use'));
    $this->assertSession()->fieldNotExists(sprintf(self::ROW, 'ch_slack1', 'cc'));
  }

  /**
   * A new form webhook in the same save as channels is refused.
   */
  public function testWebhookWithChannelsIsRefused(): void {
    $this->connect();
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->submitForm([
      sprintf(self::ROW, 'ch_email1', 'use') => TRUE,
      'quant_webform_secrets[slack_webhook]' => 'https://hooks.slack.com/services/T1/B1/both1234',
    ], 'Save');
    $this->assertSession()->pageTextContains('A form with alert channels does not use a Slack webhook. Remove the webhook, or untick the channels.');
    $this->assertSame([], $this->storedChannels());
    $this->assertNull(\Drupal::keyValue(QuantFormsMapper::SECRETS)->get('slack_webhook:contact_us'));
  }

  /**
   * Without the Quant permission, the message asks for an administrator.
   */
  public function testNoLinkWithoutPermission(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer webform']));
    $this->connect();
    \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', 403);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->pageTextContains('Ask a site administrator to reconnect to Quant.');
    $this->assertSession()->linkNotExists('Reconnect to Quant');
    $this->assertSession()->linkByHrefNotExists('/oauth/connect');

    // A site with a project token only.
    $this->config('quant_api.settings')->set('auth_method', 'token')->save();
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->pageTextContains('Ask a site administrator to connect to Quant.');
    $this->assertSession()->linkNotExists('Connect to Quant');
  }

  /**
   * A login that the dashboard refuses (403) must log in again.
   */
  public function testReconnect(): void {
    $this->connect();
    \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', 403);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->linkExists('Reconnect to Quant');
    $this->assertSession()->linkByHrefExists('/admin/config/development/quant/api/oauth/connect');
    $this->assertSession()->pageTextNotContains('The alert channels are not available now.');
  }

  /**
   * With the list unavailable, a save keeps the stored channels as they are.
   */
  public function testUnavailableKeepsSelection(): void {
    $this->connect();
    $this->storeChannels([['id' => 'ch_email1', 'cc' => TRUE], ['id' => 'ch_slack1', 'cc' => FALSE]]);
    \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', 500);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->pageTextContains('The alert channels are not available now.');
    $this->assertSession()->pageTextContains('This form keeps its 2 picked channels.');
    $this->submitForm(['third_party_settings[quant_webform][enabled]' => TRUE], 'Save');
    $this->assertSession()->pageTextContains('Webform settings Contact us has been saved.');
    $this->assertSame([['id' => 'ch_email1', 'cc' => TRUE], ['id' => 'ch_slack1', 'cc' => FALSE]], $this->storedChannels());

    // The list fails between the page load and the save: still kept.
    \Drupal::state()->delete('quant_api_oauth_test.alert_channels_status');
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    \Drupal::state()->set('quant_api_oauth_test.alert_channels_status', 500);
    $this->submitForm([sprintf(self::ROW, 'ch_email1', 'use') => FALSE, sprintf(self::ROW, 'ch_email2', 'use') => TRUE], 'Save');
    $this->assertSame([['id' => 'ch_email1', 'cc' => TRUE], ['id' => 'ch_slack1', 'cc' => FALSE]], $this->storedChannels());
  }

  /**
   * A posted ID that is not listed (or stored) is not stored; cc is email only.
   */
  public function testForgedPostIsRefused(): void {
    $this->connect();
    // The page shows ch_forged, and ch_slack1 as an email channel.
    \Drupal::state()->set('quant_api_oauth_test.alert_channels', [
      ['id' => 'ch_email1', 'name' => 'Enquiries', 'type' => 'email', 'hint' => ''],
      ['id' => 'ch_forged', 'name' => 'Forged', 'type' => 'email', 'hint' => ''],
      ['id' => 'ch_slack1', 'name' => 'Web team', 'type' => 'email', 'hint' => ''],
    ]);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    // The save sees the real list.
    \Drupal::state()->set('quant_api_oauth_test.alert_channels', [
      ['id' => 'ch_email1', 'name' => 'Enquiries', 'type' => 'email', 'hint' => ''],
      ['id' => 'ch_slack1', 'name' => 'Web team', 'type' => 'slack', 'hint' => '#web'],
    ]);
    $this->submitForm([
      sprintf(self::ROW, 'ch_forged', 'use') => TRUE,
      sprintf(self::ROW, 'ch_forged', 'cc') => TRUE,
      sprintf(self::ROW, 'ch_slack1', 'use') => TRUE,
      sprintf(self::ROW, 'ch_slack1', 'cc') => TRUE,
    ], 'Save');
    $this->assertSame([['id' => 'ch_slack1', 'cc' => FALSE]], $this->storedChannels());
  }

  /**
   * A stored channel that the list no longer has is shown and kept.
   */
  public function testMissingStoredChannel(): void {
    $this->connect();
    $this->storeChannels([['id' => 'ch_gone', 'cc' => TRUE], ['id' => 'ch_email1', 'cc' => FALSE]]);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $session = $this->assertSession();
    $session->pageTextContains('Channel no longer exists');
    $session->checkboxChecked(sprintf(self::ROW, 'ch_gone', 'use'));
    // A missing channel keeps the cc it has.
    $session->checkboxChecked(sprintf(self::ROW, 'ch_gone', 'cc'));
    $this->submitForm([], 'Save');
    $this->assertSame([['id' => 'ch_email1', 'cc' => FALSE], ['id' => 'ch_gone', 'cc' => TRUE]], $this->storedChannels());

    $this->submitForm([sprintf(self::ROW, 'ch_gone', 'use') => FALSE], 'Save');
    $this->assertSame([['id' => 'ch_email1', 'cc' => FALSE]], $this->storedChannels());
  }

  /**
   * Without a Quant login, the stored channels stay and still publish.
   */
  public function testNotConnectedKeepsChannels(): void {
    $this->storeChannels([['id' => 'ch_email1', 'cc' => FALSE]]);
    $this->drupalGet('admin/structure/webform/manage/contact_us/settings');
    $this->assertSession()->linkExists('Connect to Quant');
    $this->assertSession()->linkByHrefExists('/admin/config/development/quant/api');
    $this->assertSession()->linkByHrefNotExists('/oauth/connect');
    $this->submitForm(['third_party_settings[quant_webform][enabled]' => TRUE], 'Save');
    $this->assertSame([['id' => 'ch_email1', 'cc' => FALSE]], $this->storedChannels());
    $config = \Drupal::service('quant_webform.mapper')->map($this->webform(), '/x')['config'];
    $this->assertSame(['ch_email1'], $config['notifications']['channels']);
    $this->assertSame([], \Drupal::state()->get('quant_api_oauth_test.requests', []));
  }

  /**
   * The webform as the site stored it, not as this process cached it.
   */
  protected function webform(): Webform {
    $storage = \Drupal::entityTypeManager()->getStorage('webform');
    $storage->resetCache(['contact_us']);
    return $storage->load('contact_us');
  }

}
