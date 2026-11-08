<?php

namespace Drupal\Tests\quant_purger\Kernel;

use Drupal\Core\Cache\CacheableResponse;
use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_purger\StackMiddleware\UrlRegistrar;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The export token: genuine or not, and what the purger records with it.
 *
 * From 2.3 the export fetch always sends the signed token. On a site with
 * content drafts off it was not sent before, so the purger recorded no
 * traffic there; that stays so.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class ExportTokenRegistrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'purge', 'quant', 'quant_api', 'quant_purger',
    'taxonomy', 'views', 'field', 'text', 'filter', 'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    // Not all of quant's config: its metadata views have schema gaps (R18).
    $this->installConfig(['quant_api', 'quant_purger']);
    $this->config('quant.token_settings')
      ->set('secret', bin2hex(random_bytes(32)))
      ->set('timeout', '+1 minute')
      ->save();
  }

  /**
   * Only a token this site signed, and not expired, is genuine.
   *
   * @covers \Drupal\quant\TokenManager::isGenuine
   */
  public function testGenuineToken() : void {
    $manager = $this->container->get('quant.token_manager');
    $token = $manager->create('/contact');
    $this->assertTrue($manager->isGenuine($token));
    $this->assertFalse($manager->isGenuine('x'));
    $this->assertFalse($manager->isGenuine(NULL));
    [$header, $payload] = explode('.', $token);
    $this->assertFalse($manager->isGenuine("$header.$payload.forged"));

    $this->config('quant.token_settings')->set('timeout', '-1 minute')->save();
    $expired = $this->container->get('quant.token_manager')->create('/contact');
    $this->assertFalse($this->container->get('quant.token_manager')->isGenuine($expired));
  }

  /**
   * With content drafts off the purger records no export traffic.
   */
  public function testDraftsOffRecordsNothing() : void {
    $registrar = new UrlRegistrar(
      $this->createMock(HttpKernelInterface::class),
      $this->container->get('quant_purger.registry'),
      $this->container->get('config.factory'),
    );
    $request = Request::create('/contact');
    $request->headers->set('quant-token', 'any');

    $response = new CacheableResponse();
    $response->getCacheableMetadata()->addCacheTags(['node:1']);

    $this->config('quant.settings')->set('disable_content_drafts', TRUE)->save();
    $this->assertFalse($registrar->determine($request, $response));

    $this->config('quant.settings')->set('disable_content_drafts', FALSE)->save();
    $this->assertTrue($registrar->determine($request, $response));
  }

}
