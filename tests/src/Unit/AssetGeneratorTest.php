<?php

namespace Drupal\Tests\quant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\quant\AssetGenerator;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;

/**
 * Tests on-demand CSS/JS aggregate generation.
 *
 * Drupal >= 10.1 only writes aggregates to disk when the requested filename
 * hash matches current library definitions; on mismatch the asset controller
 * returns a 301 to the corrected filename and the requested file never
 * appears on disk. The generator must capture the response body and persist
 * it at the path the markup references so it can be pushed to Quant.
 *
 * @coversDefaultClass \Drupal\quant\AssetGenerator
 * @group quant
 */
class AssetGeneratorTest extends UnitTestCase {

  /**
   * Temporary directory for generated files.
   *
   * @var string
   */
  protected $tempDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/quant-asset-' . uniqid();
    mkdir($this->tempDir, 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->tempDir)) {
      $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->tempDir);
    }
    parent::tearDown();
  }

  /**
   * Get a stubbed config factory for quant.settings.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   The config factory stub.
   */
  protected function getConfigStub() {
    $values = [
      'local_server' => 'http://localhost',
      'host_domain' => 'example.com',
      'ssl_cert_verify' => FALSE,
    ];

    $stub = $this->prophesize(ConfigFactoryInterface::class);
    $config = $this->prophesize(ImmutableConfig::class);

    foreach ($values as $key => $value) {
      $config->get($key)->willReturn($value);
    }

    $stub->get('quant.settings')->willReturn($config->reveal());
    return $stub->reveal();
  }

  /**
   * The response body is written to disk when core does not dump the file.
   *
   * @covers ::generate
   */
  public function testGenerateWritesResponseBody() {
    $destination = $this->tempDir . '/css/css_hash.css';
    $original_path = '/sites/default/files/css/css_hash.css?delta=1&language=en&theme=conga&include=abc';

    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', 'http://localhost' . $original_path, Argument::that(function ($options) {
      return $options['allow_redirects'] !== FALSE
        && $options['headers']['Host'] === 'example.com'
        && $options['http_errors'] === FALSE;
    }))->willReturn(new Response(200, [], 'body{color:red}'));

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertTrue($generator->generate($original_path, $destination));
    $this->assertFileExists($destination);
    $this->assertEquals('body{color:red}', file_get_contents($destination));
  }

  /**
   * Nested destination directories are created as needed.
   *
   * @covers ::generate
   */
  public function testGenerateCreatesNestedDirectories() {
    $destination = $this->tempDir . '/a/b/c/css_hash.css';

    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::type('string'), Argument::type('array'))
      ->willReturn(new Response(200, [], 'body{}'));

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertTrue($generator->generate('/sites/default/files/css/css_hash.css?delta=0', $destination));
    $this->assertFileExists($destination);
  }

  /**
   * No request is made when the file already exists on disk.
   *
   * @covers ::generate
   */
  public function testGenerateSkipsExistingFile() {
    $destination = $this->tempDir . '/css_hash.css';
    file_put_contents($destination, 'existing');

    $http = $this->prophesize(ClientInterface::class);
    $http->request(Argument::cetera())->shouldNotBeCalled();

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertTrue($generator->generate('/sites/default/files/css/css_hash.css?delta=0', $destination));
    $this->assertEquals('existing', file_get_contents($destination));
  }

  /**
   * The file dumped by core during the request is kept, not overwritten.
   *
   * When the filename hash matches, core writes the aggregate itself as a
   * side effect of the priming request.
   *
   * @covers ::generate
   */
  public function testGenerateKeepsFileDumpedByCore() {
    $destination = $this->tempDir . '/css_hash.css';

    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::type('string'), Argument::type('array'))
      ->will(function () use ($destination) {
        // Simulate core dumping the aggregate during the request.
        file_put_contents($destination, 'dumped-by-core');
        return new Response(200, [], 'response-body');
      });

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertTrue($generator->generate('/sites/default/files/css/css_hash.css?delta=0', $destination));
    $this->assertEquals('dumped-by-core', file_get_contents($destination));
  }

  /**
   * A non-200 response writes nothing and reports failure.
   *
   * @covers ::generate
   */
  public function testGenerateFailsOnErrorResponse() {
    $destination = $this->tempDir . '/css_hash.css';

    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::type('string'), Argument::type('array'))
      ->willReturn(new Response(400, [], 'Bad request'));

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertFalse($generator->generate('/sites/default/files/css/css_hash.css', $destination));
    $this->assertFileDoesNotExist($destination);
  }

  /**
   * An empty response body writes nothing and reports failure.
   *
   * @covers ::generate
   */
  public function testGenerateFailsOnEmptyBody() {
    $destination = $this->tempDir . '/css_hash.css';

    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::type('string'), Argument::type('array'))
      ->willReturn(new Response(200, [], ''));

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertFalse($generator->generate('/sites/default/files/css/css_hash.css?delta=0', $destination));
    $this->assertFileDoesNotExist($destination);
  }

  /**
   * A connection error reports failure rather than throwing.
   *
   * @covers ::generate
   */
  public function testGenerateFailsOnConnectionError() {
    $destination = $this->tempDir . '/css_hash.css';

    $http = $this->prophesize(ClientInterface::class);
    $http->request('GET', Argument::type('string'), Argument::type('array'))
      ->willThrow(new \Exception('Connection refused'));

    $generator = new AssetGenerator($http->reveal(), $this->getConfigStub());

    $this->assertFalse($generator->generate('/sites/default/files/css/css_hash.css?delta=0', $destination));
    $this->assertFileDoesNotExist($destination);
  }

}
