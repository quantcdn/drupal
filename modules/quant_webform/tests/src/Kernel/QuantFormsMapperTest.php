<?php

namespace Drupal\Tests\quant_webform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant_webform\EventSubscriber\QuantFormsSubscriber;
use Drupal\quant_webform\QuantFormsMapper;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Maps Webforms to Quant Forms configs, as in the support matrix.
 *
 * @coversDefaultClass \Drupal\quant_webform\QuantFormsMapper
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class QuantFormsMapperTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'file', 'node', 'taxonomy', 'views',
    'webform', 'quant', 'quant_api', 'quant_webform',
  ];

  /**
   * The webform module's own config has schema gaps on some versions.
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform', 'quant_webform']);
    $this->config('system.site')->set('mail', 'site@example.com')->set('name', 'Tolmerra Water')->save();
  }

  /**
   * The mapper.
   */
  protected function mapper(): QuantFormsMapper {
    return $this->container->get('quant_webform.mapper');
  }

  /**
   * A Webform from YAML elements and settings.
   */
  protected function webform(string $elements, array $settings = [], array $handlers = []): Webform {
    $webform = Webform::create([
      'id' => 'test_' . strtolower($this->randomMachineName(6)),
      'title' => 'Test form',
      'elements' => $elements,
    ]);
    foreach ($settings as $name => $value) {
      $webform->setSetting($name, $value);
    }
    foreach ($handlers as $id => $handler) {
      $handler += ['handler_id' => $id, 'status' => TRUE, 'weight' => 0];
      $plugin = \Drupal::service('plugin.manager.webform.handler')->createInstance($handler['id'], $handler);
      $webform->addWebformHandler($plugin);
    }
    $webform->save();
    return $webform;
  }

  /**
   * The elements of the demo's service request, cut down to what matters.
   */
  protected const WIZARD = <<<YAML
page_about:
  '#type': webform_wizard_page
  '#title': 'About you'
  applicant_type:
    '#type': radios
    '#title': 'I am a'
    '#options': { residential: Residential, business: Business }
    '#required': true
  name_container:
    '#type': container
    first_name: { '#type': textfield, '#title': 'First name', '#required': true }
  business_name:
    '#type': textfield
    '#title': 'Business name'
    '#required': true
    '#states': { invisible: { ':input[name="applicant_type"]': { value: residential } } }
  email: { '#type': email, '#title': 'Email', '#required': true }
page_request:
  '#type': webform_wizard_page
  '#title': 'Your request'
  connection_details:
    '#type': fieldset
    '#title': 'Connection details'
    '#states': { visible: { ':input[name="request_type"]': { value: connection } } }
    lot_count: { '#type': number, '#title': 'Lots', '#required': true }
  services:
    '#type': checkboxes
    '#title': 'Services'
    '#options': { water: Water, sewer: Sewer }
    '#required': true
  request_type:
    '#type': select
    '#title': 'Request'
    '#options': { connection: Connection, meter: Meter }
    '#required': true
page_location:
  '#type': webform_wizard_page
  '#title': 'Location'
  property_address:
    '#type': webform_custom_composite
    '#title': 'Property address'
    '#required': true
    '#multiple': false
    '#element':
      street: { '#type': textfield, '#title': Street, '#required': true }
      suburb: { '#type': textfield, '#title': Suburb }
  mailing:
    '#type': webform_address
    '#title': 'Mailing address'
    '#required': true
    '#city__required': true
  attachments:
    '#type': managed_file
    '#title': 'Photos'
    '#multiple': 3
    '#required': true
  consent: { '#type': checkbox, '#title': 'I agree', '#required': true }
YAML;

  /**
   * Always-required fields only; conditional, grouped and file fields left out.
   *
   * @covers ::mandatoryFields
   * @covers ::map
   */
  public function testWizardMandatoryAndRemovedFields(): void {
    $webform = $this->webform(self::WIZARD, ['preview' => 1]);
    $report = $this->mapper()->map($webform, '/form/service-request');

    $this->assertTrue($report['supported']);
    $this->assertSame(
      ['applicant_type', 'first_name', 'email', 'request_type', 'property_address[street]', 'mailing[city]', 'consent'],
      $report['config']['mandatory_fields'],
    );
    $this->assertContains('attachments[fids]', $report['config']['remove_fields']);
    $this->assertContains('form_build_id', $report['config']['remove_fields']);
    $this->assertSame('/form/service-request', $report['config']['target_url']);
    $notes = implode(' ', array_map('strval', $report['notes']));
    $this->assertStringContainsString('Wizard pages run in the browser', $notes);
    $this->assertStringContainsString('business_name is required only under conditions', strip_tags($notes));
  }

  /**
   * AJAX submit blocks registration; drafts and limits are notes.
   *
   * @covers ::map
   */
  public function testAjaxBlocksAndOtherSettingsAreNotes(): void {
    $webform = $this->webform("name: { '#type': textfield, '#title': Name }", [
      'ajax' => TRUE,
      'draft' => 'all',
      'limit_total' => 10,
      'confirmation_type' => 'message',
    ]);
    $report = $this->mapper()->map($webform, '/form/x');
    $this->assertFalse($report['supported']);
    $this->assertCount(1, $report['blocking']);
    $this->assertCount(2, $report['notes']);
  }

  /**
   * The first email handler becomes the Quant email notification.
   *
   * @covers ::map
   */
  public function testEmailHandlerAndMessages(): void {
    $webform = $this->webform("email: { '#type': email, '#title': Email }", [
      'confirmation_type' => 'page',
      'confirmation_message' => '<p>Thank you. Your reference is <strong>SR-[webform_submission:serial]</strong>.</p><p>We reply soon.</p>',
    ], [
      'email' => [
        'id' => 'email',
        'settings' => [
          'states' => ['completed'],
          'to_mail' => 'enquiries@example.com',
          'cc_mail' => '[webform_submission:values:email:raw]',
          'from_mail' => '_default',
          'subject' => 'Service request SR-[webform_submission:serial] from [webform_submission:values:email]',
          'reply_to' => '[webform_submission:values:email:raw]',
          'html' => TRUE,
        ],
      ],
      'remote' => ['id' => 'remote_post', 'settings' => []],
    ]);
    $report = $this->mapper()->map($webform, '/form/x');
    $email = $report['config']['notifications']['email'];

    $this->assertSame('enquiries@example.com', $email['to']);
    $this->assertSame('site@example.com', $email['from']);
    $this->assertArrayNotHasKey('cc', $email, 'A token address cannot be sent.');
    $this->assertSame('Service request SR- from {{email}}', $email['subject']);
    $this->assertSame('{{email}}', $email['reply_to']);
    $this->assertFalse($email['options']['text_only']);
    $this->assertSame('Thank you. Your reference is SR-. We reply soon.', $report['config']['success_message']);
    $this->assertStringContainsString('does not run on the static site', implode(' ', array_map('strval', $report['notes'])));
    $this->assertSame('Tolmerra Water', $report['config']['notification_settings']['org_name']);
  }

  /**
   * The per-form override wins, and messages fit the Quant limit.
   *
   * @covers ::successMessage
   */
  public function testMessageOverrideAndLimit(): void {
    $webform = $this->webform("name: { '#type': textfield, '#title': Name }");
    $webform->setThirdPartySetting('quant_webform', 'success_message', str_repeat('Thanks a lot. ', 40))->save();
    $message = $this->mapper()->successMessage($webform);
    $this->assertLessThanOrEqual(QuantFormsMapper::MESSAGE_MAX, mb_strlen($message));
    $this->assertStringStartsWith('Thanks a lot.', $message);
  }

  /**
   * The Slack webhook comes from KeyValue, never config.
   *
   * @covers ::map
   */
  public function testSlackFromSecretsStore(): void {
    $webform = $this->webform("name: { '#type': textfield, '#title': Name }");
    $this->assertArrayNotHasKey('slack', $this->mapper()->map($webform, '/x')['config']['notifications']);

    \Drupal::keyValue(QuantFormsMapper::SECRETS)->set('slack_webhook', 'https://hooks.slack.com/services/test');
    $slack = $this->mapper()->map($webform, '/x')['config']['notifications']['slack'];
    $this->assertSame(['webhook' => 'https://hooks.slack.com/services/test', 'enabled' => TRUE], $slack);

    $webform->setThirdPartySetting('quant_webform', 'slack', FALSE)->save();
    $this->assertArrayNotHasKey('slack', $this->mapper()->map($webform, '/x')['config']['notifications']);
  }

  /**
   * Webform tokens become Quant tokens where they map one to one.
   *
   * @covers ::convertTokens
   */
  public function testConvertTokens(): void {
    $this->assertSame('New request from {{email}} ({{first_name}})', QuantFormsMapper::convertTokens('New request from [webform_submission:values:email:raw] ([webform_submission:values:first_name])'));
    $this->assertSame('Request', QuantFormsMapper::convertTokens('Request [webform_submission:serial]'));
  }

  /**
   * The subscriber adds the config to the push of a page with a Webform.
   *
   * @covers \Drupal\quant_webform\EventSubscriber\QuantFormsSubscriber::onOutput
   */
  public function testSubscriberAddsTheFormConfigToThePush(): void {
    $webform = $this->webform("name: { '#type': textfield, '#title': Name, '#required': true }");
    $html = '<html><body><form data-quant-webform="' . $webform->id() . '"></form></body></html>';
    $event = new QuantEvent($html, '/contact', ['published' => TRUE]);
    $this->container->get('quant_webform.subscriber')->onOutput($event);
    $meta = $event->getMetadata();
    $this->assertTrue($meta['form_enabled']);
    $this->assertSame(['name'], $meta['form_config']['mandatory_fields']);

    // A closed form and a page without a form get nothing.
    $webform->setStatus(Webform::STATUS_CLOSED)->save();
    $closed = new QuantEvent($html, '/contact', []);
    $this->container->get('quant_webform.subscriber')->onOutput($closed);
    $this->assertArrayNotHasKey('form_enabled', $closed->getMetadata());
    $this->assertSame([], QuantFormsSubscriber::webformIds('<form id="search"></form>'));

    // A form that posts to its own page belongs to that page only: a footer
    // newsletter does not take over the contact page's config.
    $page = '<form data-quant-webform="contact"></form>'
      . '<form action="/newsletter" data-quant-webform="newsletter" data-quant-target="/newsletter"></form>';
    $this->assertSame(['contact'], QuantFormsSubscriber::webformIds($page, '/contact-us'));
    $this->assertSame(['newsletter'], QuantFormsSubscriber::webformIds('<form data-quant-webform="newsletter" data-quant-target="/newsletter">', '/newsletter'));
  }

}
