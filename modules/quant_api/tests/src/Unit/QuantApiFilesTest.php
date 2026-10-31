<?php

namespace Drupal\Tests\quant_api\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Site\Settings;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\quant_api\EventSubscriber\QuantApi;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests which files a page push sends on to Quant.
 *
 * @coversDefaultClass \Drupal\quant_api\EventSubscriber\QuantApi
 * @group quant
 */
class QuantApiFilesTest extends UnitTestCase {

  /**
   * The host_domain the site publishes as.
   */
  const HOST = 'www.example.com';

  /**
   * The URL paths dispatched as file events.
   *
   * @var string[]
   */
  protected $pushed = [];

  /**
   * The mocked Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $client;

  /**
   * The mocked seed queue.
   *
   * @var \Drupal\Core\Queue\QueueInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $queue;

  /**
   * The subscriber under test.
   *
   * @var \Drupal\quant_api\EventSubscriber\QuantApi
   */
  protected $subscriber;

  /**
   * A unique directory under the public files directory for this test.
   *
   * @var string
   */
  protected $dir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', sys_get_temp_dir() . '/quant-drupal-root');
    }

    $this->dir = '/sites/default/files/quant-test-' . uniqid();
    mkdir(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails', 0775, TRUE);
    file_put_contents(DRUPAL_ROOT . $this->dir . '/Terms (legacy).pdf', '%PDF');
    file_put_contents(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails/abc.jpg', 'jpg');
    file_put_contents(DRUPAL_ROOT . '/sites/default/quant-secret.php', '<?php');
    file_put_contents(DRUPAL_ROOT . $this->dir . '/nick+w1.png', 'png');
    file_put_contents(DRUPAL_ROOT . $this->dir . '/what-is-cpq?.png', 'png');

    // The editing domain is one of this site's hosts; other.example.org is
    // another website.
    new Settings([
      'trusted_host_patterns' => ['^www\.example\.com$', '^editing\.example\.com$'],
      'file_public_path' => 'sites/default/files',
    ]);

    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnMap([
      ['host_domain', self::HOST],
      ['xpath_selectors', '//a[@rel="next"]'],
    ]);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($settings);

    $this->queue = $this->createMock(QueueInterface::class);
    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')->willReturn($this->queue);

    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('queue', $queue_factory);
    \Drupal::setContainer($container);

    // Record file events instead of sending them.
    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(QuantFileEvent::OUTPUT, function (QuantFileEvent $event) {
      $this->pushed[] = $event->getUrl();
    });

    $this->client = $this->createMock(QuantClientInterface::class);
    $this->subscriber = new QuantApi($this->client, $logger_factory, $dispatcher);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink(DRUPAL_ROOT . $this->dir . '/Terms (legacy).pdf');
    @unlink(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails/abc.jpg');
    @rmdir(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails');
    @rmdir(DRUPAL_ROOT . $this->dir);
    @unlink(DRUPAL_ROOT . '/sites/default/quant-secret.php');
    @unlink(DRUPAL_ROOT . $this->dir . '/nick+w1.png');
    @unlink(DRUPAL_ROOT . $this->dir . '/what-is-cpq?.png');
    parent::tearDown();
  }

  /**
   * Pushes a page whose API response lists the given documents.
   *
   * @param string $markup
   *   The page markup.
   * @param array $documents
   *   The attachments the API returns in media.documents.
   */
  protected function pushPage(string $markup, array $documents = []) : void {
    $this->client->method('send')->willReturn([
      'attachments' => ['media' => ['documents' => $documents]],
    ]);

    $meta = [
      'published' => TRUE,
      'transitions' => [],
      'info' => [],
      'proxy_override' => FALSE,
    ];
    $this->subscriber->onOutput(new QuantEvent($markup, '/terms', $meta));
  }

  /**
   * A document linked by the site's editing hostname is pushed.
   *
   * @covers ::onOutput
   */
  public function testDocumentLinkedByTrustedHostIsPushed() {
    $encoded = str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $this->dir . '/Terms (legacy).pdf');

    $this->pushPage('<html><body></body></html>', [
      [
        'path' => $encoded,
        'original_path' => 'https://editing.example.com' . $encoded,
      ],
    ]);

    $this->assertSame([$this->dir . '/Terms (legacy).pdf'], $this->pushed);
  }

  /**
   * A document of the same path on another website is not pushed.
   *
   * @covers ::onOutput
   */
  public function testDocumentOnOtherHostIsSkipped() {
    $this->queue->expects($this->never())->method('createItem');

    $this->pushPage('<html><body></body></html>', [
      [
        'path' => $this->dir . '/Terms (legacy).pdf',
        'original_path' => 'https://other.example.org' . $this->dir . '/Terms (legacy).pdf',
      ],
      [
        'path' => $this->dir . '/missing.pdf',
        'original_path' => 'https://editing.example.com' . $this->dir . '/missing.pdf',
      ],
    ]);

    $this->assertSame([], $this->pushed);
  }

  /**
   * A relative document link is still pushed as before.
   *
   * @covers ::onOutput
   */
  public function testRelativeDocumentIsPushed() {
    $this->pushPage('<html><body></body></html>', [
      [
        'path' => $this->dir . '/Terms (legacy).pdf',
        'original_path' => $this->dir . '/Terms (legacy).pdf',
      ],
    ]);

    $this->assertSame([$this->dir . '/Terms (legacy).pdf'], $this->pushed);
  }

  /**
   * A local file named only in JSON-LD is pushed once.
   *
   * @covers ::onOutput
   */
  public function testStructuredDataThumbnailIsPushed() {
    $thumbnail = $this->dir . '/oembed_thumbnails/abc.jpg';
    $json = json_encode([
      '@context' => 'https://schema.org',
      '@graph' => [
        [
          '@type' => 'VideoObject',
          'thumbnailUrl' => 'https://' . self::HOST . $thumbnail,
          'embedUrl' => 'https://www.youtube.com/embed/xyz',
        ],
        [
          '@type' => 'ImageObject',
          'url' => 'https://' . self::HOST . $thumbnail,
        ],
        [
          '@type' => 'ImageObject',
          'url' => 'https://other.example.org' . $thumbnail,
        ],
        [
          '@type' => 'ImageObject',
          'url' => 'https://' . self::HOST . $this->dir . '/oembed_thumbnails/missing.jpg',
        ],
      ],
    ]);

    $this->pushPage('<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>');

    $this->assertSame([$thumbnail], $this->pushed);
  }

  /**
   * A JSON-LD file the attachments already covered is not pushed twice.
   *
   * @covers ::onOutput
   */
  public function testStructuredDataSkipsAttachments() {
    $thumbnail = $this->dir . '/oembed_thumbnails/abc.jpg';
    $json = json_encode(['image' => 'https://' . self::HOST . $thumbnail]);

    $this->pushPage('<html><head><script type="application/ld+json">' . $json . '</script></head><body><img src="' . $thumbnail . '"></body></html>', [
      ['path' => $thumbnail, 'original_path' => $thumbnail],
    ]);

    $this->assertSame([$thumbnail], $this->pushed);
  }

  /**
   * A path that climbs out of the public files directory is never pushed.
   *
   * @covers ::onOutput
   */
  public function testPathOutsidePublicFilesIsNotPushed() {
    $escape = '/sites/default/files/../quant-secret.php';
    $json = json_encode(['image' => 'https://' . self::HOST . $escape]);
    $this->queue->expects($this->never())->method('createItem');

    $this->pushPage('<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>', [
      [
        'path' => $escape,
        'original_path' => 'https://editing.example.com' . $escape,
      ],
    ]);

    $this->assertSame([], $this->pushed);
  }

  /**
   * Returns a protected property of a queued item.
   *
   * @param object $item
   *   The queue item.
   * @param string $name
   *   The property name.
   *
   * @return mixed
   *   The property value.
   */
  protected function itemProperty(object $item, string $name) {
    $property = new \ReflectionProperty($item, $name);
    $property->setAccessible(TRUE);
    return $property->getValue($item);
  }

  /**
   * A "+" or an encoded "?" in a file name survives the API's decoding.
   *
   * The API sends path already decoded ("+" as a space, "%3F" as "?"), so the
   * name comes from the raw full_path.
   *
   * @covers ::onOutput
   */
  public function testSpecialCharacterNamesUseFullPath() {
    $this->queue->expects($this->never())->method('createItem');

    $this->pushPage('<html><body></body></html>', [
      [
        'path' => $this->dir . '/nick w1.png?itok=abc',
        'full_path' => $this->dir . '/nick%2Bw1.png?itok=abc',
        'original_path' => $this->dir . '/nick%2Bw1.png?itok=abc',
      ],
      [
        'path' => $this->dir . '/what-is-cpq?.png?itok=def',
        'full_path' => $this->dir . '/what-is-cpq%3F.png?itok=def',
        'original_path' => $this->dir . '/what-is-cpq%3F.png?itok=def',
      ],
    ]);

    $this->assertSame([
      $this->dir . '/nick%2Bw1.png?itok=abc',
      $this->dir . '/what-is-cpq%3F.png?itok=def',
    ], $this->pushed);
  }

  /**
   * Preload imagesrcset files are pushed, or queued for generation.
   *
   * @covers ::onOutput
   */
  public function testPreloadImageSrcsetFiles() {
    $style = $this->dir . '/styles/small_hq/public/image/a.png.webp';
    $queued = [];
    $this->queue->method('createItem')->willReturnCallback(function ($item) use (&$queued) {
      $queued[] = $item;
      return TRUE;
    });

    $srcset = 'https://' . self::HOST . $this->dir . '/oembed_thumbnails/abc.jpg 640w, '
      . 'https://' . self::HOST . $style . '?itok=Ie-YtCdi 768w';
    $this->pushPage('<html><head><link rel="preload" as="image" imagesrcset="' . $srcset . '"></head><body></body></html>');

    $this->assertSame([$this->dir . '/oembed_thumbnails/abc.jpg'], $this->pushed);
    $this->assertCount(1, $queued);
    $this->assertSame($style, $this->itemProperty($queued[0], 'file'));
    $this->assertSame($style . '?itok=Ie-YtCdi', $this->itemProperty($queued[0], 'fullPath'));
  }

  /**
   * A JSON-LD image style that is not generated yet is queued with its itok.
   *
   * @covers ::onOutput
   */
  public function testStructuredDataStyleIsQueued() {
    $style = $this->dir . '/styles/large/public/image/2026-03/Conga%20main.png';
    $queued = [];
    $this->queue->method('createItem')->willReturnCallback(function ($item) use (&$queued) {
      $queued[] = $item;
      return TRUE;
    });

    $json = json_encode(['@type' => 'ImageObject', 'url' => 'https://' . self::HOST . $style . '?itok=oyDPMs0U']);
    $this->pushPage('<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>');

    $this->assertSame([], $this->pushed);
    $this->assertCount(1, $queued);
    $this->assertSame(rawurldecode($style), $this->itemProperty($queued[0], 'file'));
    $this->assertSame($style . '?itok=oyDPMs0U', $this->itemProperty($queued[0], 'fullPath'));
  }

  /**
   * A queued style path that climbs out with ".." is refused.
   *
   * @covers ::onOutput
   */
  public function testStyleWithDotDotIsNotQueued() {
    $this->queue->expects($this->never())->method('createItem');
    $json = json_encode(['url' => 'https://' . self::HOST . '/sites/default/files/styles/../../quant-secret.php?itok=x']);

    $this->pushPage('<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>');

    $this->assertSame([], $this->pushed);
  }

  /**
   * A JSON-LD file name with "+" and a space is pushed percent-encoded.
   *
   * @covers ::onOutput
   */
  public function testStructuredDataNameIsEncoded() {
    $json = json_encode(['image' => 'https://' . self::HOST . $this->dir . '/nick%2Bw1.png']);

    $this->pushPage('<html><head><script type="application/ld+json">' . $json . '</script></head><body></body></html>');

    $this->assertSame([$this->dir . '/nick%2Bw1.png'], $this->pushed);
  }

  /**
   * A relative attachment that climbs out of the web root is refused.
   *
   * @covers ::onOutput
   */
  public function testRelativeAttachmentWithDotDotIsRefused() {
    $this->queue->expects($this->never())->method('createItem');
    $escape = '/sites/default/files/../quant-secret.php';

    $this->pushPage('<html><body></body></html>', [
      ['path' => $escape, 'original_path' => $escape],
    ]);

    $this->assertSame([], $this->pushed);
  }

}
