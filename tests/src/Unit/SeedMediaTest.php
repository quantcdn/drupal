<?php

namespace Drupal\Tests\quant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Seed;
use Drupal\quant\Utility;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests that a saved media entity pushes its files.
 *
 * @coversDefaultClass \Drupal\quant\Seed
 * @group quant
 */
class SeedMediaTest extends UnitTestCase {

  /**
   * The URL paths dispatched as file events.
   *
   * @var string[]
   */
  protected $pushed = [];

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
    file_put_contents(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails/abc.jpg', 'jpg');
    file_put_contents(DRUPAL_ROOT . $this->dir . '/Terms (legacy).pdf', '%PDF');

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn(NULL);
    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->willReturn($config);

    $dispatcher = new EventDispatcher();
    $dispatcher->addListener(QuantFileEvent::OUTPUT, function (QuantFileEvent $event) {
      $this->pushed[] = $event->getUrl();
    });

    $container = new ContainerBuilder();
    $container->set('config.factory', $config_factory);
    $container->set('event_dispatcher', $dispatcher);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    @unlink(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails/abc.jpg');
    @unlink(DRUPAL_ROOT . $this->dir . '/Terms (legacy).pdf');
    @rmdir(DRUPAL_ROOT . $this->dir . '/oembed_thumbnails');
    @rmdir(DRUPAL_ROOT . $this->dir);
    parent::tearDown();
  }

  /**
   * Builds a file entity mock.
   *
   * @param string $uri
   *   The file URI.
   * @param string $url
   *   The relative URL the file is served at.
   *
   * @return \Drupal\file\FileInterface
   *   The file.
   */
  protected function file(string $uri, string $url) : FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('getFileUri')->willReturn($uri);
    $file->method('createFileUrl')->willReturn($url);
    return $file;
  }

  /**
   * Builds a media entity mock.
   *
   * @param string $source_field
   *   The source field name.
   * @param array $fields
   *   Field lists keyed by field name.
   *
   * @return \Drupal\media\MediaInterface
   *   The media entity.
   */
  protected function media(string $source_field, array $fields) : MediaInterface {
    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getConfiguration')->willReturn(['source_field' => $source_field]);

    $media = $this->createMock(MediaInterface::class);
    $media->method('getSource')->willReturn($source);
    $media->method('hasField')->willReturnCallback(fn($name) => isset($fields[$name]));
    $media->method('get')->willReturnCallback(fn($name) => $fields[$name]);
    return $media;
  }

  /**
   * Builds an entity reference field list mock.
   *
   * @param array $entities
   *   The referenced entities.
   *
   * @return \Drupal\Core\Field\EntityReferenceFieldItemListInterface
   *   The field list.
   */
  protected function references(array $entities) : EntityReferenceFieldItemListInterface {
    $items = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $items->method('referencedEntities')->willReturn($entities);
    return $items;
  }

  /**
   * An oEmbed media item pushes its downloaded thumbnail.
   *
   * The source field of a remote video is a plain string, not a file.
   *
   * @covers ::seedMedia
   */
  public function testRemoteVideoPushesThumbnail() {
    $media = $this->media('field_media_oembed_video', [
      'thumbnail' => $this->references([
        $this->file('public://' . substr($this->dir, 21) . '/oembed_thumbnails/abc.jpg', $this->dir . '/oembed_thumbnails/abc.jpg'),
      ]),
      'field_media_oembed_video' => $this->createMock(FieldItemListInterface::class),
    ]);

    Seed::seedMedia($media);

    $this->assertSame([$this->dir . '/oembed_thumbnails/abc.jpg'], $this->pushed);
  }

  /**
   * A document media item pushes its source file under its decoded path.
   *
   * @covers ::seedMedia
   * @covers ::seedFile
   */
  public function testDocumentPushesSourceFile() {
    $media = $this->media('field_media_document', [
      'thumbnail' => $this->references([]),
      'field_media_document' => $this->references([
        $this->file('public://Terms (legacy).pdf', $this->dir . '/Terms%20%28legacy%29.pdf'),
      ]),
    ]);

    Seed::seedMedia($media);

    $this->assertSame([$this->dir . '/Terms (legacy).pdf'], $this->pushed);
  }

  /**
   * A private file is not pushed.
   *
   * @covers ::seedFile
   */
  public function testPrivateFileIsSkipped() {
    $media = $this->media('field_media_document', [
      'field_media_document' => $this->references([
        $this->file('private://Terms (legacy).pdf', '/system/files/Terms%20%28legacy%29.pdf'),
      ]),
    ]);

    Seed::seedMedia($media);

    $this->assertSame([], $this->pushed);
  }

  /**
   * Hosts that match a trusted host pattern belong to this site.
   *
   * @covers \Drupal\quant\Utility::isTrustedHostUrl
   */
  public function testIsTrustedHostUrl() {
    new Settings(['trusted_host_patterns' => ['^example\.com$', '\.example\.com$']]);

    $this->assertTrue(Utility::isTrustedHostUrl('https://editing.example.com/sites/default/files/a.pdf'));
    $this->assertTrue(Utility::isTrustedHostUrl('https://EXAMPLE.com/a.pdf'));
    $this->assertFalse(Utility::isTrustedHostUrl('https://example.com.evil.org/a.pdf'));
    $this->assertFalse(Utility::isTrustedHostUrl('/sites/default/files/a.pdf'));

    new Settings([]);
    $this->assertFalse(Utility::isTrustedHostUrl('https://editing.example.com/a.pdf'));
  }

}
