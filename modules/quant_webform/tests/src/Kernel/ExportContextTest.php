<?php

namespace Drupal\Tests\quant_webform\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_webform\ExportContext;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * An export request is one with a genuine token made for its own page.
 *
 * @coversDefaultClass \Drupal\quant_webform\ExportContext
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class ExportContextTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'field', 'text', 'filter', 'file', 'node', 'taxonomy', 'views',
    'webform', 'quant', 'quant_api', 'quant_webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('quant.token_settings')->set('secret', bin2hex(random_bytes(32)))->set('timeout', '+1 minute')->save();
  }

  /**
   * A request with a token for its path, at the root or in a subdirectory.
   *
   * @covers ::isExportRequest
   */
  public function testTokenForItsOwnPage(): void {
    $manager = $this->container->get('quant.token_manager');

    $request = Request::create('/contact');
    $request->headers->set('quant-token', $manager->create('/contact'));
    $this->assertTrue(ExportContext::isExportRequest($request));

    // A site in /drupal signs the route with its base path.
    $request = Request::create('/drupal/contact', 'GET', [], [], [], [
      'SCRIPT_NAME' => '/drupal/index.php',
      'SCRIPT_FILENAME' => '/var/www/drupal/index.php',
    ]);
    $this->assertSame('/contact', $request->getPathInfo());
    $request->headers->set('quant-token', $manager->create('/drupal/contact'));
    $this->assertTrue(ExportContext::isExportRequest($request));

    // A token for another page is not an export request.
    $request = Request::create('/careers');
    $request->headers->set('quant-token', $manager->create('/contact'));
    $this->assertFalse(ExportContext::isExportRequest($request));
  }

}
