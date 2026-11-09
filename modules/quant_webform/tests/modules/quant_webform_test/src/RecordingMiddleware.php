<?php

namespace Drupal\quant_webform_test;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Answers and records every call to the Quant content API.
 */
class RecordingMiddleware {

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
        $log = $this->state->get('quant_webform_test.calls', []);
        $log[] = [
          'method' => $request->getMethod(),
          'path' => $request->getUri()->getPath(),
          'body' => json_decode((string) $request->getBody(), TRUE),
        ];
        $this->state->set('quant_webform_test.calls', $log);
        $fail = $request->getUri()->getPath() === '/v1/form' && $this->state->get('quant_webform_test.fail_form');
        return Create::promiseFor(new Response($fail ? 500 : 200, ['Content-Type' => 'application/json'], '{}'));
      };
    };
  }

}
