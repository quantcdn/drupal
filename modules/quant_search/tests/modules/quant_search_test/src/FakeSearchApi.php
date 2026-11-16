<?php

namespace Drupal\quant_search_test;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Answers the Quant content API as a project with Algolia search enabled.
 *
 * Records every call (method, path, JSON body, Quant-Url header) in state.
 * Answers every call with a 500 while quant_search_test.fail is set.
 */
class FakeSearchApi {

  public function __construct(protected StateInterface $state, protected LockBackendInterface $lock) {}

  /**
   * The Guzzle middleware.
   */
  public function __invoke() {
    return function (callable $handler) {
      return function (RequestInterface $request, array $options) use ($handler) {
        if ($request->getUri()->getHost() !== 'api.quantcdn.io') {
          return $handler($request, $options);
        }
        $this->record([
          'method' => $request->getMethod(),
          'path' => $request->getUri()->getPath(),
          'body' => json_decode((string) $request->getBody(), TRUE),
          // Unpublish names its path in a header, not in the body.
          'quant_url' => $request->getHeaderLine('Quant-Url'),
        ]);
        // With quant_search_test.fail set, every call fails as a 500 does.
        if ($this->state->get('quant_search_test.fail')) {
          return Create::rejectionFor(new RequestException('Server error', $request, new Response(500)));
        }
        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->answer($request))));
      };
    };
  }

  /**
   * Adds a call to the list in state.
   *
   * Under a lock and from a fresh read: a publish runs after the response,
   * at the same time as the next request of the test, and both add calls.
   */
  protected function record(array $call): void {
    if (!$this->lock->acquire('quant_search_test.calls')) {
      $this->lock->wait('quant_search_test.calls');
      $this->lock->acquire('quant_search_test.calls');
    }
    $this->state->resetCache();
    $log = $this->state->get('quant_search_test.calls', []);
    $log[] = $call;
    $this->state->set('quant_search_test.calls', $log);
    $this->lock->release('quant_search_test.calls');
  }

  /**
   * The body of the answer.
   */
  protected function answer(RequestInterface $request): array {
    if ($request->getUri()->getPath() === '/v1/ping') {
      return [
        'project' => 'test-project',
        'config' => [
          'search_enabled' => TRUE,
          'search_index' => [
            'algolia_application_id' => 'TESTAPP',
            'algolia_read_key' => 'test-read-key',
            'algolia_index' => 'test_index',
          ],
        ],
      ];
    }
    if ($request->getUri()->getPath() === '/v1/search') {
      return [
        'index' => ['name' => 'test_index'],
        'settings' => ['attributesForFaceting' => ['content_type', 'tags_en']],
      ];
    }
    return [];
  }

}
