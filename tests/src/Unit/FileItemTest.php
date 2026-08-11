<?php

namespace Drupal\Tests\quant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\quant\AssetGenerator;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Plugin\QueueItem\FileItem;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Tests queued file items can generate missing on-demand aggregates.
 *
 * @coversDefaultClass \Drupal\quant\Plugin\QueueItem\FileItem
 * @group quant
 */
class FileItemTest extends UnitTestCase {

  /**
   * The Quant project the container resolves to.
   */
  const PROJECT = 'test-project';

  /**
   * The mocked event dispatcher.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $eventDispatcher;

  /**
   * The mocked asset generator.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $assetGenerator;

  /**
   * The mocked logger channel.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!defined('DRUPAL_ROOT')) {
      define('DRUPAL_ROOT', sys_get_temp_dir() . '/quant-drupal-root');
    }
    if (!is_dir(DRUPAL_ROOT)) {
      mkdir(DRUPAL_ROOT, 0775, TRUE);
    }

    $this->eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
    $this->assetGenerator = $this->prophesize(AssetGenerator::class);
    $this->logger = $this->prophesize(LoggerChannelInterface::class);

    $logger_factory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $logger_factory->get('quant')->willReturn($this->logger->reveal());

    // Queue items stamp the project they are created for, which reads
    // quant_api.settings through the config factory.
    $config = $this->prophesize(ImmutableConfig::class);
    $config->get('api_project')->willReturn(self::PROJECT);

    $config_factory = $this->prophesize(ConfigFactoryInterface::class);
    $config_factory->get('quant_api.settings')->willReturn($config->reveal());

    $container = new ContainerBuilder();
    $container->set('event_dispatcher', $this->eventDispatcher->reveal());
    $container->set('quant.asset_generator', $this->assetGenerator->reveal());
    $container->set('logger.factory', $logger_factory->reveal());
    $container->set('config.factory', $config_factory->reveal());
    \Drupal::setContainer($container);
  }

  /**
   * A missing CSS aggregate is generated and then dispatched.
   *
   * @covers ::send
   */
  public function testSendGeneratesMissingAggregate() {
    $file = '/sites/default/files/css/css_' . uniqid() . '.css';
    $original_path = $file . '?delta=1&language=en&theme=conga&include=abc';

    $this->assetGenerator->generate($original_path, DRUPAL_ROOT . $file)
      ->will(function ($args) {
        // Simulate the generator persisting the aggregate.
        if (!is_dir(dirname($args[1]))) {
          mkdir(dirname($args[1]), 0775, TRUE);
        }
        file_put_contents($args[1], 'body{}');
        return TRUE;
      })
      ->shouldBeCalledOnce();

    $this->eventDispatcher->dispatch(Argument::type(QuantFileEvent::class), QuantFileEvent::OUTPUT)
      ->willReturnArgument(0)
      ->shouldBeCalledOnce();

    $item = new FileItem([
      'file' => $file,
      'url' => $file,
      'original_path' => $original_path,
    ]);
    $item->send();
  }

  /**
   * Nothing is dispatched and a warning is logged when generation fails.
   *
   * @covers ::send
   */
  public function testSendLogsWhenGenerationFails() {
    $file = '/sites/default/files/css/css_' . uniqid() . '.css';
    $original_path = $file . '?delta=1&language=en&theme=conga&include=abc';

    $this->assetGenerator->generate($original_path, DRUPAL_ROOT . $file)
      ->willReturn(FALSE)
      ->shouldBeCalledOnce();

    $this->eventDispatcher->dispatch(Argument::cetera())->shouldNotBeCalled();
    $this->logger->warning(Argument::containingString($file))->shouldBeCalled();

    $item = new FileItem([
      'file' => $file,
      'url' => $file,
      'original_path' => $original_path,
    ]);
    $item->send();
  }

  /**
   * The generator is not used when the file already exists on disk.
   *
   * @covers ::send
   */
  public function testSendSkipsGenerationForExistingFile() {
    $file = '/sites/default/files/css/css_' . uniqid() . '.css';
    if (!is_dir(dirname(DRUPAL_ROOT . $file))) {
      mkdir(dirname(DRUPAL_ROOT . $file), 0775, TRUE);
    }
    file_put_contents(DRUPAL_ROOT . $file, 'body{}');

    $this->assetGenerator->generate(Argument::cetera())->shouldNotBeCalled();
    $this->eventDispatcher->dispatch(Argument::type(QuantFileEvent::class), QuantFileEvent::OUTPUT)
      ->willReturnArgument(0)
      ->shouldBeCalledOnce();

    $item = new FileItem([
      'file' => $file,
      'url' => $file,
      'original_path' => $file . '?delta=0',
    ]);
    $item->send();
  }

  /**
   * Non-aggregate files without an original path keep existing behavior.
   *
   * @covers ::send
   */
  public function testSendIgnoresMissingFileWithoutOriginalPath() {
    $file = '/sites/default/files/missing-' . uniqid() . '.pdf';

    $this->assetGenerator->generate(Argument::cetera())->shouldNotBeCalled();
    $this->eventDispatcher->dispatch(Argument::cetera())->shouldNotBeCalled();

    $item = new FileItem([
      'file' => $file,
      'url' => $file,
    ]);
    $item->send();
  }

  /**
   * Items record the project resolved when they were created.
   *
   * The worker compares this stamp against the project it is publishing to,
   * so that an item queued for one site is never sent to another.
   *
   * @covers ::getTargetProject
   */
  public function testItemStampsTargetProject() {
    $item = new FileItem([
      'file' => '/sites/default/files/example.pdf',
      'url' => '/sites/default/files/example.pdf',
    ]);

    $this->assertEquals(self::PROJECT, $item->getTargetProject());
  }

}
