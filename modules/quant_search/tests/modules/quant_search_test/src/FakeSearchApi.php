<?php

namespace Drupal\quant_search_test;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Answers the Quant content API as a project with Algolia search enabled.
 *
 * Records every call (method, path, JSON body, Quant-Url header) in state.
 */
class FakeSearchApi {

  public function __construct(protected StateInterface $state) {}

  /**
   * The Guzzle middleware.
   */
  public function __invoke() {
    return function (callable $handler) {
      return function (RequestInterface $request, array $options) use ($handler) {
        if ($request->getUri()->getHost() !== 'api.quantcdn.io') {
          return $handler($request, $options);
        }
        $log = $this->state->get('quant_search_test.calls', []);
        $log[] = [
          'method' => $request->getMethod(),
          'path' => $request->getUri()->getPath(),
          'body' => json_decode((string) $request->getBody(), TRUE),
          // Unpublish names its path in a header, not in the body.
          'quant_url' => $request->getHeaderLine('Quant-Url'),
        ];
        $this->state->set('quant_search_test.calls', $log);
        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($this->answer($request))));
      };
    };
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
