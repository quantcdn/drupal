<?php

namespace Drupal\Tests\quant_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\quant_api\Client\QuantClient;
use GuzzleHttp\Client;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;

/**
 * Ensure that the client responds correctly.
 */
class QuantClientTest extends UnitTestCase {

  /**
   * Get a stubbed config factory.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config interface.
   */
  protected function getConfigStub($default = []) {
    $value = [
      'api_account' => 'account',
      'api_token' => 'token',
      'api_endpoint' => 'http://test',
    ] + $default;

    $stub = $this->prophesize(ConfigFactoryInterface::class);
    $config = $this->prophesize(ImmutableConfig::class);

    foreach ($config as $key => $value) {
      $config->get($key)->willReturn($value);
    }

    $stub->get('quant_api.settings')->willReturn($config);
    return $stub;
  }

  /**
   * Get a successful project response.
   *
   * @return GuzzleHttp\Psr7\Response
   *   A response object.
   */
  protected function getProjectResponse() {
    // @todo should these be fixtures.
    $body = [
      'project' => 'test',
      'error' => FALSE,
      'errorMsg' => '',
    ];

    $res = $this->prophesize(Response::class);
    $res->getStatusCode->willReturn(200);
    $res->getBody()->willReturn(json_encode($body));

    return $res;
  }

  /**
   * A valid redirect response.
   *
   * @return GuzzleHttp\Psr7\Response
   *   A response object.
   */
  protected function getRedirectResponse() {
    $body = [
      'redirect_url' => '/b',
      'quant_revision' => 1,
      'url' => '/a',
      'redirect_http_code' => 302,
      'errorMsg' => '',
      'error' => FALSE,
    ];

    $res = $this->prophesize(Response::class);
    $res->getStatusCode->willReturn(200);
    $res->getBody()->willReturn(json_encode($body));

    return $res;
  }

  /**
   * Get an invalid response.
   *
   * @return GuzzleHttp\Psr7\Response
   *   A response object.
   */
  protected function getInvalidResponse() {
    $body = [
      'error' => TRUE,
      'errorMsg' => 'Error',
    ];

    $res = $this->prophesize(Response::class);
    $res->getStatusCode->willReturn(400);
    $res->getBody->willReturn(json_encode($body));

    return $res;
  }

  /**
   * Ensure that the client handles a failed ping to QuantAPI.
   */
  public function testPingClientError() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();

    $http->get('http://test/ping', [
      'http_errors' => FALSE,
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
      'exception' => FALSE,
    ])->willThrow(new RequestException('ERROR'));

    $client = new QuantClient($http, $config, $logger);
    $this->assertFalse($client->ping());
  }

  /**
   * Ensure a valid ping can be made.
   */
  public function testPingValid() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getProjectResponse();

    $http->get('http://test/ping', [
      'http_errors' => FALSE,
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
      'exception' => FALSE,
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);
    $project = $client->ping();

    $this->assertEquals($project, 'test');
  }

  /**
   * Ensure that ping handles an invalid response from the server.
   */
  public function testPingInvalid() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getInvalidResponse();

    $http->get('http://test/ping', [
      'http_errors' => FALSE,
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
      'exception' => FALSE,
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);

    $this->assertFalse($client->ping());
  }

  /**
   * Ensure that send can send a valid payload.
   */
  public function testSendValid() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getProjectResponse();

    $http->post('http://test', [
      RequestOptions::JSON => [],
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);
    $this->assertEquals(['project' => 'test'], $client->send([]));
  }

  /**
   * Ensure that send handles server errors.
   *
   * @expectedException GuzzleHttp\Exception\RequestException
   */
  public function testSendError() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getInvalidResponse();

    $http->post('http://test', [
      RequestOptions::JSON => [],
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);
    $client->send([]);
  }

  /**
   * Ensure a valid redirect response is sent.
   */
  public function testSendRedirectValid() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getRedirectResponse();

    $http->post('http://test/redirect', [
      RequestOptions::JSON => [],
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);
    $redirect = $client->sendRedirect([]);

    $this->assertEquals([
      'redirect_url' => '/b',
      'quant_revision' => 1,
      'url' => '/a',
      'redirect_http_code' => 302,
      'errorMsg' => '',
      'error' => FALSE,
    ], $redirect);
  }

  /**
   * Ensure a valid redirect response is sent.
   *
   * @expectedException GuzzleHttp\Exception\RequestException
   */
  public function testSendRedirectError() {
    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getInvalidResponse();

    $http->post('http://test/redirect', [
      RequestOptions::JSON => [],
      'headers' => [
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);
    $client->sendRedirect([]);
  }

  /**
   * Ensure files are validated before sending.
   *
   * @expectedException Drupal\quant_api\Exception\InvalidPayload
   */
  public function testSendFileFileNoExist() {
    // phpcs:ignore
    global $exists_return;
    // phpcs:ignore
    global $readable_return;

    $exists_return = FALSE;
    $readable_return = FALSE;

    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();

    $client = new QuantClient($http, $config, $logger);
    $client->sendFile('/tmp/test', '/url');
  }

  /**
   * Ensure files are validated before sending.
   */
  public function testSendFileValid() {
    // phpcs:ignore
    global $exists_return;
    // phpcs:ignore
    global $readable_return;

    $exists_return = TRUE;
    $readable_return = TRUE;

    $http = $this->prophesize(Client::class);
    $logger = $this->prophesize(LoggerChannelFactoryInterface::class);
    $config = $this->getConfigStub();
    $res = $this->getProjectResponse();

    $http->post('http://test', [
      'headers' => [
        'Quant-File-Url' => '/url',
        'Quant-Customer' => 'account',
        'Quant-Token' => 'token',
      ],
      'multipart' => [
        [
          'name' => 'filename',
          'filename' => 'test.jpg',
          'contents' => [],
        ],
      ],
    ])->willReturn($res);

    $client = new QuantClient($http, $config, $logger);
    $client->sendFile('/tmp/test.jpg', '/url');
  }

}

//
// Hacky... this is a hack to stub php built-ins so we
// can correctly test the send file method.
//
namespace Drupal\quant_api\Client;

/**
 * Stub file_exists.
 */
function file_exists($path) {
  // phpcs:ignore
  global $exists_return;
  if (isset($exists_return)) {
    return $exists_return;
  }
  return call_user_func_array('\file_exists', func_get_args());
}

/**
 * Stub is_readable.
 */
function is_readable($path) {
  // phpcs:ignore
  global $readable_return;
  if (isset($readable_return)) {
    return $readable_return;
  }
  return call_user_func_array('\file_exists', func_get_args());
}

/**
 * Stub fopen.
 */
function fopen($file, $opts) {
  return [];
}
