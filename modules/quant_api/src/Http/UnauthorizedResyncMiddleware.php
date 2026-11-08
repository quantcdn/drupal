<?php

namespace Drupal\quant_api\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * On a 401 from the content API, re-reads the project write token once.
 *
 * A write token rotated in the dashboard then recovers on the next publish
 * instead of after the daily sync. WriteTokenSync::syncAfterUnauthorized()
 * allows one try every 15 minutes and acts only for a site that logs in to
 * Quant.
 */
class UnauthorizedResyncMiddleware {

  /**
   * The Guzzle middleware.
   */
  public function __invoke() {
    return function (callable $handler) {
      return function (RequestInterface $request, array $options) use ($handler) {
        return $handler($request, $options)->then(function (ResponseInterface $response) use ($request) {
          if ($response->getStatusCode() === 401 && $request->hasHeader('Quant-Token')) {
            // Lazy: the sync service itself uses the HTTP client.
            \Drupal::service('quant_api.write_token_sync')->syncAfterUnauthorized();
          }
          return $response;
        });
      };
    };
  }

}
