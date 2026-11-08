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
    $this->assertSame('/webform/service_request', $off[0]['body']['url'] ?? NULL);
    $this->assertFalse($off[0]['body']['form_enabled']);
    $this->assertSame(['released_by' => 'drupal'], $off[0]['body']['form_config']['notification_settings']);
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
   * A form closed with no save goes off on cron; a failure is tried again.
   */
  public function testClosedFormIsTurnedOffOnCronWithRetry(): void {
    $this->connectTestProject();
    // Registered while open; cron never saw it open.
    \Drupal::service('quant_webform.registry')->record('/webform/service_request', 'service_request');
    $past = (new \DateTime('-1 hour'))->format('Y-m-d\\TH:i:s');
    $earlier = (new \DateTime('-1 day'))->format('Y-m-d\\TH:i:s');
    \Drupal::state()->set('quant_webform_test.fail_form', TRUE);
    Webform::load('service_request')->setStatus(Webform::STATUS_SCHEDULED)->set('open', $earlier)->set('close', $past)->save();
    \Drupal::service('quant_webform.registry')->record('/webform/service_request', 'service_request');
    \Drupal::state()->set('quant_webform_test.calls', []);

    // Quant fails: the page stays registered.
    quant_webform_cron();
    $this->assertSame(['/webform/service_request'], \Drupal::service('quant_webform.registry')->pagesOf('service_request'));

    // Next cron, Quant answers: the page is turned off and released.
    \Drupal::state()->set('quant_webform_test.fail_form', FALSE);
    \Drupal::state()->set('quant_webform_test.calls', []);
    quant_webform_cron();
    $off = array_values(array_filter(\Drupal::state()->get('quant_webform_test.calls', []), fn ($call) => $call['path'] === '/v1/form'));
    $this->assertSame('/webform/service_request', $off[0]['body']['url'] ?? NULL);
    $this->assertFalse($off[0]['body']['form_enabled']);
    // The dashboard may manage the page again: no "managed in Drupal" marker.
    $this->assertArrayNotHasKey('managed_by', $off[0]['body']['form_config']['notification_settings']);
    $this->assertSame([], \Drupal::service('quant_webform.registry')->pagesOf('service_request'));
  }

  /**
   * A made-up header is served from the page cache; a genuine token is not.
   */
  public function testOnlyGenuineTokenSkipsThePageCache(): void {
    $this->drupalGet('webform/service_request', [], ['quant-token' => 'made-up']);
    $this->drupalGet('webform/service_request', [], ['quant-token' => 'made-up']);
    $this->assertSession()->responseHeaderEquals('X-Drupal-Cache', 'HIT');

    $this->getSession()->restart();
    $this->drupalGet('webform/service_request', [], ['quant-token' => $this->exportToken()]);
    $this->assertSession()->responseHeaderNotEquals('X-Drupal-Cache', 'HIT');
    $this->assertSession()->elementExists('css', 'form[data-quant-webform]');
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
