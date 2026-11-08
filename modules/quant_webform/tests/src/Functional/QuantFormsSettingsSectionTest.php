<?php

namespace Drupal\Tests\quant_webform\Functional;

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
    'node', 'file', 'webform', 'quant', 'quant_api', 'quant_webform', 'quant_webform_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The quant and webform modules' shipped config has schema gaps.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Webform::create([
      'id' => 'contact_us',
      'title' => 'Contact us',
      'elements' => "email: { '#type': email, '#title': Email }",
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['administer webform']));
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
    $this->assertFalse((bool) Webform::load('contact_us')->getThirdPartySetting('quant_webform', 'enabled'));

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

    $settings = Webform::load('contact_us')->getThirdPartySettings('quant_webform');
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

}
