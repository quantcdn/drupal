<?php

namespace Drupal\Tests\quant_api\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\quant_api\Client\QuantClient;
use Drupal\quant_api\Exception\InvalidPayload;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Ensures the client forms correct requests and reads responses.
 *
 * Requests are driven through a Guzzle MockHandler and captured with the
 * history middleware, so the assertions describe what reaches the wire —
 * method, URI, headers and body — rather than the shape of an options array.
 * Every request must carry Quant-Project, because that header alone decides
 * which site the content is published to.
 *
 * @coversDefaultClass \Drupal\quant_api\Client\QuantClient
 *
 * @group quant_api
 */
class QuantClientTest extends UnitTestCase {

  /**
   * Requests recorded by the history middleware.
   *
   * @var array
   */
  protected $history = [];

  /**
   * Temporary files created by a test, removed on teardown.
   *
   * @var string[]
   */
  protected $tempFiles = [];

  /**
   * The credentials every test configures.
   */
  const ACCOUNT = 'test-account';
  const PROJECT = 'test-project';
  const TOKEN = 'test-token';

  /**
   * The endpoint the client derives from the configured base.
   */
  const ENDPOINT = 'http://test/v1';

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    // The client reports transport and subscription errors through
    // \Drupal::messenger() rather than an injected service, so a container
    // has to exist before those paths run.
    $container = new ContainerBuilder();
    $container->set('messenger', $this->createMock(MessengerInterface::class));
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() : void {
    foreach ($this->tempFiles as $file) {
      if (file_exists($file)) {
        unlink($file);
      }
    }
    parent::tearDown();
  }

  /**
   * Builds a client whose transport returns the given responses in order.
   *
   * @param array $responses
   *   Responses or exceptions for the MockHandler to yield.
   * @param array $overrides
   *   Configuration values to override.
   *
   * @return \Drupal\quant_api\Client\QuantClient
   *   The client under test.
   */
  protected function client(array $responses, array $overrides = []) : QuantClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    return new QuantClient(
      new Client(['handler' => $stack]),
      $this->configFactory($overrides),
      $this->createMock(LoggerChannelFactoryInterface::class)
    );
  }

  /**
   * Builds a config factory returning the test credentials.
   *
   * @param array $overrides
   *   Configuration values to override.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory double.
   */
  protected function configFactory(array $overrides = []) : ConfigFactoryInterface {
    $values = $overrides + [
      'api_account' => self::ACCOUNT,
      'api_project' => self::PROJECT,
      'api_token' => self::TOKEN,
      'api_endpoint' => 'http://test',
      'api_tls_disabled' => FALSE,
    ];

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      fn($key) => $values[$key] ?? NULL
    );

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    return $factory;
  }

  /**
   * Returns the request the client sent.
   *
   * @param int $index
   *   Which recorded request to return.
   *
   * @return \Psr\Http\Message\RequestInterface
   *   The captured request.
   */
  protected function request(int $index = 0) {
    $this->assertArrayHasKey($index, $this->history, 'The client sent a request.');
    return $this->history[$index]['request'];
  }

  /**
   * Asserts the request carries the credentials that route it to a project.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   The captured request.
   */
  protected function assertAuthHeaders($request) : void {
    $this->assertEquals(self::ACCOUNT, $request->getHeaderLine('Quant-Customer'));
    $this->assertEquals(self::PROJECT, $request->getHeaderLine('Quant-Project'));
    $this->assertEquals(self::TOKEN, $request->getHeaderLine('Quant-Token'));
  }

  /**
   * Creates a real temporary file for the upload tests.
   *
   * @param string $extension
   *   The file extension to use.
   *
   * @return string
   *   The path to the file.
   */
  protected function tempFile(string $extension = 'jpg') : string {
    $path = tempnam(sys_get_temp_dir(), 'quant') . '.' . $extension;
    file_put_contents($path, 'test contents');
    $this->tempFiles[] = $path;
    return $path;
  }

  /**
   * A successful ping returns TRUE.
   *
   * @covers ::ping
   */
  public function testPingValid() {
    $client = $this->client([new Response(200, [], json_encode(['project' => 'test']))]);

    $this->assertTrue($client->ping());

    $request = $this->request();
    $this->assertEquals('GET', $request->getMethod());
    $this->assertEquals(self::ENDPOINT . '/ping', (string) $request->getUri());
    $this->assertAuthHeaders($request);
  }

  /**
   * A non-200 ping returns FALSE.
   *
   * @covers ::ping
   */
  public function testPingInvalid() {
    $client = $this->client([new Response(500, [], json_encode(['error' => TRUE]))]);

    $this->assertFalse($client->ping());
  }

  /**
   * A transport failure during ping is caught and reported as FALSE.
   *
   * @covers ::ping
   */
  public function testPingClientError() {
    $error = new RequestException('ERROR', new Request('GET', self::ENDPOINT . '/ping'));

    $this->assertFalse($this->client([$error])->ping());
  }

  /**
   * The project endpoint decodes the response body.
   *
   * @covers ::project
   */
  public function testProjectValid() {
    $body = ['project' => 'test', 'config' => ['search_enabled' => TRUE]];
    $client = $this->client([new Response(200, [], json_encode($body))]);

    $project = $client->project();

    $this->assertEquals('test', $project->project);
    $this->assertTrue($project->config->search_enabled);
    $this->assertAuthHeaders($this->request());
  }

  /**
   * A valid send returns the decoded payload.
   *
   * @covers ::send
   */
  public function testSendValid() {
    $client = $this->client([new Response(200, [], json_encode(['project' => 'test']))]);

    $this->assertEquals(['project' => 'test'], $client->send(['url' => '/a']));

    $request = $this->request();
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals(self::ENDPOINT, (string) $request->getUri());
    $this->assertEquals(['url' => '/a'], json_decode((string) $request->getBody(), TRUE));
    $this->assertAuthHeaders($request);
  }

  /**
   * A transport failure during send is not swallowed.
   *
   * @covers ::send
   */
  public function testSendError() {
    $error = new RequestException('ERROR', new Request('POST', self::ENDPOINT));

    $this->expectException(RequestException::class);
    $this->client([$error])->send([]);
  }

  /**
   * A valid redirect send returns the decoded payload.
   *
   * @covers ::sendRedirect
   */
  public function testSendRedirectValid() {
    $body = [
      'url' => '/a',
      'redirect_url' => '/b',
      'redirect_http_code' => 302,
      'error' => FALSE,
    ];
    $client = $this->client([new Response(200, [], json_encode($body))]);

    $this->assertEquals($body, $client->sendRedirect(['url' => '/a']));
    $this->assertEquals(self::ENDPOINT . '/redirect', (string) $this->request()->getUri());
  }

  /**
   * A transport failure during a redirect send is not swallowed.
   *
   * @covers ::sendRedirect
   */
  public function testSendRedirectError() {
    $error = new RequestException('ERROR', new Request('POST', self::ENDPOINT . '/redirect'));

    $this->expectException(RequestException::class);
    $this->client([$error])->sendRedirect([]);
  }

  /**
   * A missing file is rejected before any request is made.
   *
   * @covers ::sendFile
   */
  public function testSendFileFileNoExist() {
    $client = $this->client([]);

    $this->expectException(InvalidPayload::class);
    $client->sendFile('/tmp/quant-does-not-exist-' . uniqid(), '/url');
  }

  /**
   * A directory is rejected, since it is readable but not a file.
   *
   * @covers ::sendFile
   */
  public function testSendFileDirectoryRejected() {
    $client = $this->client([]);

    $this->expectException(InvalidPayload::class);
    $client->sendFile(sys_get_temp_dir(), '/url');
  }

  /**
   * A readable file is uploaded as multipart with the target url attached.
   *
   * @covers ::sendFile
   */
  public function testSendFileValid() {
    $file = $this->tempFile();
    $client = $this->client([new Response(200, [], json_encode(['project' => 'test']))]);

    $this->assertEquals(['project' => 'test'], $client->sendFile($file, '/url'));

    $request = $this->request();
    $this->assertEquals('POST', $request->getMethod());
    $this->assertEquals(self::ENDPOINT, (string) $request->getUri());
    $this->assertEquals('/url', $request->getHeaderLine('Quant-File-Url'));
    $this->assertAuthHeaders($request);

    // The body is a multipart stream naming the file and carrying its bytes.
    $body = (string) $request->getBody();
    $this->assertStringContainsString(basename($file), $body);
    $this->assertStringContainsString('test contents', $body);
  }

  /**
   * Unpublish sends a PATCH naming the url to withdraw.
   *
   * @covers ::unpublish
   */
  public function testUnpublish() {
    $client = $this->client([new Response(200, [], json_encode(['published' => FALSE]))]);

    $this->assertEquals(['published' => FALSE], $client->unpublish('/a'));

    $request = $this->request();
    $this->assertEquals('PATCH', $request->getMethod());
    $this->assertEquals(self::ENDPOINT . '/unpublish', (string) $request->getUri());
    $this->assertEquals('/a', $request->getHeaderLine('Quant-Url'));
    $this->assertAuthHeaders($request);
  }

  /**
   * A bare url list is wrapped in the key the API expects.
   *
   * @covers ::getUrlMeta
   */
  public function testGetUrlMetaWrapsBareList() {
    $client = $this->client([new Response(200, [], json_encode(['meta' => []]))]);

    $client->getUrlMeta(['/a', '/b']);

    $request = $this->request();
    $this->assertEquals(self::ENDPOINT . '/url-meta', (string) $request->getUri());
    $this->assertEquals(
      ['Quant-Url' => ['/a', '/b']],
      json_decode((string) $request->getBody(), TRUE)
    );
  }

  /**
   * An already-wrapped url list is passed through unchanged.
   *
   * @covers ::getUrlMeta
   */
  public function testGetUrlMetaKeepsWrappedList() {
    $client = $this->client([new Response(200, [], json_encode(['meta' => []]))]);

    $client->getUrlMeta(['Quant-Url' => ['/a']]);

    $this->assertEquals(
      ['Quant-Url' => ['/a']],
      json_decode((string) $this->request()->getBody(), TRUE)
    );
  }

  /**
   * Search records are posted to the search endpoint.
   *
   * @covers ::sendSearchRecords
   */
  public function testSendSearchRecords() {
    $records = [['title' => 'A', 'url' => '/a']];
    $client = $this->client([new Response(200, [], json_encode(['count' => 1]))]);

    $this->assertEquals(['count' => 1], $client->sendSearchRecords($records));

    $request = $this->request();
    $this->assertEquals(self::ENDPOINT . '/search', (string) $request->getUri());
    $this->assertEquals($records, json_decode((string) $request->getBody(), TRUE));
    $this->assertAuthHeaders($request);
  }

  /**
   * Clearing the index deletes the whole search collection.
   *
   * @covers ::clearSearchIndex
   */
  public function testClearSearchIndex() {
    $client = $this->client([new Response(200, [], json_encode(['cleared' => TRUE]))]);

    $this->assertEquals(['cleared' => TRUE], $client->clearSearchIndex());

    $request = $this->request();
    $this->assertEquals('DELETE', $request->getMethod());
    $this->assertEquals(self::ENDPOINT . '/search/all', (string) $request->getUri());
    $this->assertAuthHeaders($request);
  }

  /**
   * Facets are posted to the facet endpoint.
   *
   * @covers ::addFacets
   */
  public function testAddFacets() {
    $facets = ['category', 'tags'];
    $client = $this->client([new Response(200, [], json_encode(['ok' => TRUE]))]);

    $client->addFacets($facets);

    $request = $this->request();
    $this->assertEquals(self::ENDPOINT . '/search/facet', (string) $request->getUri());
    $this->assertEquals($facets, json_decode((string) $request->getBody(), TRUE));
  }

  /**
   * TLS verification is on by default and off when disabled.
   *
   * @covers ::send
   */
  public function testTlsVerificationFollowsConfig() {
    $this->client([new Response(200, [], '{}')])->send([]);
    $this->assertTrue($this->history[0]['options']['verify']);

    $this->history = [];

    $this->client([new Response(200, [], '{}')], ['api_tls_disabled' => TRUE])->send([]);
    $this->assertFalse($this->history[0]['options']['verify']);
  }

  /**
   * Overrides are reported by comparing active values against the original.
   *
   * @covers ::getOverrides
   */
  public function testGetOverridesReportsChangedKeys() {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn($key) => match ($key) {
      'api_project' => 'overridden-project',
      'api_account' => self::ACCOUNT,
      'api_token' => self::TOKEN,
      'api_endpoint' => 'http://test',
      'api_tls_disabled' => FALSE,
      default => NULL,
    });
    // getOriginal() reports the pre-override value for the project only.
    $config->method('getOriginal')->willReturnCallback(fn($key) => match ($key) {
      'api_project' => 'base-project',
      'api_account' => self::ACCOUNT,
      'api_token' => self::TOKEN,
      'api_endpoint' => 'http://test',
      'api_tls_disabled' => FALSE,
      default => NULL,
    });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);

    $client = new QuantClient(
      new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
      $factory,
      $this->createMock(LoggerChannelFactoryInterface::class)
    );

    $this->assertEquals(['api_project' => 'overridden-project'], $client->getOverrides());
  }

}
