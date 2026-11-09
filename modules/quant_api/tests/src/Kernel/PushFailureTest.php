<?php

namespace Drupal\Tests\quant_api\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\quant_api\PushFailures;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A push that fails after the response is kept for the editor to see.
 *
 * Pushes run at the end of the request, after the response is sent, so the
 * editor who saved the page never saw a failure. The failure is now stored
 * for the site and shown on the next page view and in the status report.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class PushFailureTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'taxonomy',
    'views',
    'quant',
    'quant_api',
  ];

  /**
   * What the stub client does next: a Throwable, or an array to return.
   *
   * @var mixed
   */
  protected $next = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $client = $this->createMock(QuantClientInterface::class);
    foreach (['send', 'sendRedirect', 'unpublish'] as $method) {
      $client->method($method)->willReturnCallback(function () {
        if ($this->next instanceof \Throwable) {
          throw $this->next;
        }
        return $this->next;
      });
    }
    $this->container->set('quant_api.client', $client);
    $this->container->set('quant.output.api', NULL);
  }

  /**
   * The subscriber under test, built with the stub client.
   */
  protected function subscriber() {
    return $this->container->get('quant.output.api');
  }

  /**
   * A content push event for a path.
   */
  protected static function page(string $path): QuantEvent {
    return new QuantEvent('<html><body>Page</body></html>', $path, [
      'published' => TRUE,
      'transitions' => [],
      'info' => [],
      'proxy_override' => FALSE,
    ]);
  }

  /**
   * A failed push is stored with a short reason; a later success clears it.
   */
  public function testFailureIsKeptUntilThePageIsPushed(): void {
    $failures = $this->container->get('quant_api.push_failures');

    $this->next = new RequestException('Server error with the token in it', new Request('POST', 'https://user:secret@api.example/v1'), new Response(503));
    $this->subscriber()->onOutput(self::page('/news/a'));
    $this->next = new ConnectException('cURL error 7 for https://user:secret@api.example', new Request('POST', 'https://api.example'));
    $this->subscriber()->onRedirect(new QuantRedirectEvent('/old', '/news/a', 301));

    $all = $failures->all();
    $this->assertSame(['/news/a', '/old'], array_keys($all));
    $this->assertSame('publish', $all['/news/a']['operation']);
    $this->assertSame('Quant answered 503', $all['/news/a']['reason']);
    $this->assertSame('Quant could not be reached', $all['/old']['reason']);
    $this->assertStringNotContainsString('secret', serialize($all));

    // The status report says so.
    \Drupal::moduleHandler()->loadInclude('quant_api', 'install');
    $requirements = quant_api_requirements('runtime');
    $this->assertArrayHasKey('quant_api_push_failures', $requirements);
    $this->assertSame(REQUIREMENT_WARNING, $requirements['quant_api_push_failures']['severity']);
    $items = implode(' ', array_map('strval', $requirements['quant_api_push_failures']['description']['list']['#items']));
    $this->assertStringContainsString('/news/a (publish, Quant answered 503', $items);

    // A later successful push of the same page clears its failure only.
    $this->next = [];
    $this->subscriber()->onOutput(self::page('/news/a'));
    $this->assertSame(['/old'], array_keys($failures->all()));
  }

  /**
   * An unpublish of a page that is already gone is not a failure.
   */
  public function testAlreadyUnpublishedIsNoFailure(): void {
    $this->next = new \Exception('Resource is already unpublished');
    $this->subscriber()->onUnpublish(new QuantEvent('', '/gone', []));
    $this->assertSame([], $this->container->get('quant_api.push_failures')->all());
  }

  /**
   * A change to the list invalidates pages that show it.
   */
  public function testChangeInvalidatesTheCacheTag(): void {
    $failures = $this->container->get('quant_api.push_failures');
    \Drupal::cache()->set('page', 'cached', -1, [PushFailures::CACHE_TAG]);
    $failures->record('/a', 'publish', new \Exception('x'));
    $this->assertFalse(\Drupal::cache()->get('page'), 'A new failure invalidates.');

    \Drupal::cache()->set('page', 'cached', -1, [PushFailures::CACHE_TAG]);
    $failures->clear('/not-listed');
    $this->assertNotFalse(\Drupal::cache()->get('page'), 'No change, no invalidation.');
    $failures->clear('/a');
    $this->assertFalse(\Drupal::cache()->get('page'), 'A cleared failure invalidates.');
  }

  /**
   * The list keeps the newest 50 pages.
   */
  public function testTheListIsCapped(): void {
    $failures = $this->container->get('quant_api.push_failures');
    for ($i = 1; $i <= 55; $i++) {
      $failures->record('/p' . $i, 'publish', new \Exception('x'));
    }
    $all = $failures->all();
    $this->assertCount(50, $all);
    $this->assertArrayNotHasKey('/p1', $all);
    $this->assertArrayHasKey('/p55', $all);
  }

}
