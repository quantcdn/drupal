<?php

namespace Drupal\Tests\quant_webform\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Configuration > Quant > Forms: what an editor reads before ticking a form.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class QuantFormsPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'file',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Webform::create([
      'id' => 'service_request',
      'title' => 'Service request',
      'elements' => <<<YAML
page_about:
  '#type': webform_wizard_page
  '#title': 'About you'
  email: { '#type': email, '#title': 'Email', '#required': true }
page_request:
  '#type': webform_wizard_page
  '#title': 'Your request'
  description: { '#type': textarea, '#title': 'Tell us more' }
YAML,
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['configure quant', 'administer webform']));
  }

  /**
   * The page explains the opt-in first and lists each difference.
   */
  public function testThePageExplainsTheOptInAndTheDifferences(): void {
    $this->drupalGet('admin/config/development/quant/forms');
    $session = $this->assertSession();

    // The explanation comes before the table, not after the settings.
    $html = $this->getSession()->getPage()->getContent();
    $this->assertLessThan(strpos($html, 'Register the ticked forms'), strpos($html, 'Quant Forms is off for every form until you tick it.'));

    // "Works, with differences" says what the differences are.
    $session->pageTextContains('Wizard pages run in the browser.');
  }

  /**
   * The register message counts forms and pages in plain words.
   */
  public function testRegisterMessageUsesPlurals(): void {
    $this->drupalGet('admin/config/development/quant/forms');
    $this->submitForm(['forms[service_request]' => TRUE], 'Register the ticked forms');
    $session = $this->assertSession();
    $session->pageTextContains('Quant Forms is on for 1 form.');
    $session->pageTextNotContains('form(s)');
    $session->pageTextNotContains('page(s)');
  }

}
