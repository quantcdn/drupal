<?php

namespace Drupal\quant_api\Http;

use Psr\Http\Message\RequestInterface;

/**
 * Moves basic-auth userinfo from a request URI into an Authorization header.
 *
 * $settings['quant_api_endpoint_url'] may carry userinfo (an endpoint behind
 * basic auth). Removing it from the URI before the request is sent keeps it
 * out of every exception message and log line built from the request.
 */
class EndpointUserInfoMiddleware {

  /**
   * The Guzzle middleware.
   */
  public function __invoke() {
    return function (callable $handler) {
      return function (RequestInterface $request, array $options) use ($handler) {
        $userinfo = $request->getUri()->getUserInfo();
        if ($userinfo !== '') {
          $request = $request
            ->withUri($request->getUri()->withUserInfo(''))
            ->withHeader('Authorization', 'Basic ' . base64_encode(rawurldecode($userinfo)));
        }
        return $handler($request, $options);
      };
    };
  }

}
