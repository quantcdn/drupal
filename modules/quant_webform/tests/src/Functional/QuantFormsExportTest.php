<?php

namespace Drupal\Tests\quant_webform\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The static render of a Webform and its registration on save.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class QuantFormsExportTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'file',
    'page_cache',
    'webform',
    'quant',
    'quant_api',
    'quant_webform',
    'quant_webform_test',
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
   * The wizard under test.
   */
  protected Webform $webform;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Export requests in these tests carry a dummy token.
    $this->config('quant.token_settings')->set('disable', 1)->save();
    $this->webform = Webform::create([
      'id' => 'service_request',
      'title' => 'Service request',
      'elements' => <<<YAML
page_about:
  '#type': webform_wizard_page
  '#title': 'About you'
  first_name: { '#type': textfield, '#title': 'First name', '#required': true }
  email: { '#type': email, '#title': 'Email', '#required': true }
page_request:
  '#type': webform_wizard_page
  '#title': 'Your request'
  description: { '#type': textarea, '#title': 'Tell us more', '#required': true }
  attachments: { '#type': managed_file, '#title': 'Photos', '#multiple': 3 }
YAML,
    ]);
    $this->webform->setThirdPartySetting('quant_webform', 'enabled', TRUE)
      ->setSetting('preview', 1)
      ->setSetting('confirmation_type', 'url')
      ->setSetting('confirmation_url', '/thank-you')
      ->save();
    user_role_grant_permissions('anonymous', ['access content']);
  }

  /**
   * An export render shows every page; a live render shows the first only.
   */
  public function testExportRenderShowsEveryWizardPage(): void {
    $session = $this->assertSession();

    // A live visit first, so the page cache holds the live render.
    $this->drupalGet('webform/service_request');
    $session->fieldExists('first_name');
    $session->fieldNotExists('description');
    $session->elementNotExists('css', 'form[data-quant-webform]');

    $this->drupalGet('webform/service_request', [], ['quant-token' => $this->exportToken()]);
    $session->elementExists('css', 'form[data-quant-webform="service_request"]');
    $session->fieldExists('first_name');
    $session->fieldExists('description');
    $session->elementsCount('css', '[data-quant-wizard-page]', 2);
    $session->elementExists('css', '[data-quant-wizard-page="page_request"][data-quant-wizard-title="Your request"]');
    $session->elementExists('css', '#quant-form-result');
    $session->elementExists('css', 'input[data-quant-submit]');
    $session->buttonNotExists('Next >');
    $settings = $this->getDrupalSettings()['quantWebform']['service_request'];
    $this->assertTrue($settings['wizard']);
    $this->assertTrue($settings['preview']);
    $this->assertSame('url', $settings['confirmation']['type']);
    $this->assertSame('/thank-you', $settings['confirmation']['url']);

    // The live render is still the live render. Mink keeps request headers,
    // so start a new session first.
    $this->getSession()->restart();
    $this->drupalGet('webform/service_request');
    $session->elementNotExists('css', 'form[data-quant-webform]');
    $session->fieldNotExists('description');
  }

  /**
   * A form that is off for Quant Forms renders as before.
   */
  public function testFormOffForQuantRendersAsBefore(): void {
    $this->webform->setThirdPartySetting('quant_webform', 'enabled', FALSE)->save();
    $this->drupalGet('webform/service_request', [], ['quant-token' => $this->exportToken()]);
    $this->assertSession()->elementNotExists('css', 'form[data-quant-webform]');
    $this->assertSession()->fieldNotExists('description');
  }

  /**
   * Saving the Webform publishes its page with the form config inline.
   */
  public function testSavePublishesThePageWithTheFormConfig(): void {
    $this->config('quant.settings')
      ->set('local_server', $this->baseUrl)
      ->set('host_domain', parse_url($this->baseUrl, PHP_URL_HOST) . (parse_url($this->baseUrl, PHP_URL_PORT) ? ':' . parse_url($this->baseUrl, PHP_URL_PORT) : ''))
      ->save();
    $this->config('quant_api.settings')
      ->set('api_account', 'test-org')
      ->set('api_project', 'test-project')
      ->set('api_token', 'test-token')
      ->save();
    \Drupal::state()->delete('quant_webform_test.calls');

    $this->webform->setSetting('confirmation_message', 'Thanks [webform_submission:serial].')->save();

    $calls = \Drupal::state()->get('quant_webform_test.calls', []);
    $pushes = array_values(array_filter($calls, fn ($call) => $call['method'] === 'POST' && $call['path'] === '/v1' && ($call['body']['url'] ?? '') === '/webform/service_request'));
    $this->assertNotEmpty($pushes, 'The page was pushed: ' . json_encode(array_column($calls, 'path')));
    $push = end($pushes)['body'];
    $this->assertTrue($push['form_enabled']);
    $this->assertSame(['first_name', 'email', 'description'], $push['form_config']['mandatory_fields']);
    $this->assertSame('Thanks .', $push['form_config']['success_message']);
    $this->assertStringContainsString('data-quant-wizard-page="page_request"', $push['content']);

    // Closing the form turns Quant Forms off on its page.
    \Drupal::state()->delete('quant_webform_test.calls');
    $this->webform->setStatus(Webform::STATUS_CLOSED)->save();
    $calls = \Drupal::state()->get('quant_webform_test.calls', []);
    $off = array_values(array_filter($calls, fn ($call) => $call['path'] === '/v1/form'));
    $this->assertSame(['url' => '/webform/service_request', 'form_enabled' => FALSE], $off[0]['body'] ?? NULL);
  }

  /**
   * A token this site signed, as the export fetch sends it.
   */
  protected function exportToken(): string {
    return \Drupal::service('quant.token_manager')->create('/webform/service_request');
  }

  /**
   * A header that is not a signed token does not switch the render.
   */
  public function testForgedHeaderGetsTheLiveRender(): void {
    $this->drupalGet('webform/service_request', [], ['quant-token' => 'not-a-token']);
    $this->assertSession()->elementNotExists('css', 'form[data-quant-webform]');
    $this->assertSession()->fieldNotExists('description');
  }

  /**
   * A form that is not opted in is never published or turned off.
   */
  public function testFormNotOptedInIsLeftAlone(): void {
    $this->connectTestProject();
    $other = Webform::create([
      'id' => 'feedback',
      'title' => 'Feedback',
      'elements' => "name: { '#type': textfield, '#title': Name }",
    ]);
    $other->save();
    \Drupal::state()->delete('quant_webform_test.calls');
    $other->setSetting('confirmation_message', 'Thanks')->save();
    $other->setStatus(Webform::STATUS_CLOSED)->save();
    $other->delete();
    $this->assertSame([], \Drupal::state()->get('quant_webform_test.calls', []));
  }

  /**
   * A scheduled form that closes turns Quant Forms off on cron.
   */
  public function testScheduledCloseTurnsQuantFormsOff(): void {
    $this->connectTestProject();
    // Registered while open, with a close date that has now passed.
    \Drupal::service('quant_webform.registry')->record('/webform/service_request', 'service_request');
    \Drupal::keyValue('quant_webform.open_state')->set('service_request', TRUE);
    $past = (new \DateTime('-1 hour'))->format('Y-m-d\\TH:i:s');
    $earlier = (new \DateTime('-1 day'))->format('Y-m-d\\TH:i:s');
    \Drupal::state()->set('quant_webform_test.calls', []);
    Webform::load('service_request')->setStatus(Webform::STATUS_SCHEDULED)->set('open', $earlier)->set('close', $past)->save();
    $this->assertFalse(Webform::load('service_request')->isOpen());
    \Drupal::state()->set('quant_webform_test.calls', []);
    \Drupal::keyValue('quant_webform.open_state')->set('service_request', TRUE);
    \Drupal::service('quant_webform.registry')->record('/webform/service_request', 'service_request');

    quant_webform_cron();

    $off = array_values(array_filter(\Drupal::state()->get('quant_webform_test.calls', []), fn ($call) => $call['path'] === '/v1/form'));
    $this->assertSame(['url' => '/webform/service_request', 'form_enabled' => FALSE], $off[0]['body'] ?? NULL);
    $this->assertSame([], \Drupal::service('quant_webform.registry')->pagesOf('service_request'));
  }

  /**
   * Points the module at the recording fake.
   */
  protected function connectTestProject(): void {
    $this->config('quant.settings')
      ->set('local_server', $this->baseUrl)
      ->set('host_domain', parse_url($this->baseUrl, PHP_URL_HOST) . (parse_url($this->baseUrl, PHP_URL_PORT) ? ':' . parse_url($this->baseUrl, PHP_URL_PORT) : ''))
      ->save();
    $this->config('quant_api.settings')
      ->set('api_account', 'test-org')
      ->set('api_project', 'test-project')
      ->set('api_token', 'test-token')
      ->save();
  }

}
