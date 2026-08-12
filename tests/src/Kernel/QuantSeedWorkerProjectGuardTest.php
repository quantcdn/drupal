<?php

namespace Drupal\Tests\quant\Kernel;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\quant\CliDomainContext;
use Drupal\quant\Plugin\QueueItem\QuantQueueItemInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\Plugin\QueueWorker\QuantSeedWorker;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Ensures the seed worker only publishes to the project an item was queued for.
 *
 * A site serving many domains from one Drupal instance publishes each domain
 * to its own project. Items are queued in one process and sent in another,
 * and the sending process does not always share the queueing process's
 * domain. Sending an item to the wrong project puts one client's content on
 * another client's site.
 *
 * @coversDefaultClass \Drupal\quant\Plugin\QueueWorker\QuantSeedWorker
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class QuantSeedWorkerProjectGuardTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'views',
    'quant',
    'quant_api',
  ];

  /**
   * The worker under test.
   *
   * @var \Drupal\quant\Plugin\QueueWorker\QuantSeedWorker
   */
  protected $worker;

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();

    $this->installConfig(['quant_api']);

    // The negotiated domain is cached per process; tests exercise several.
    CliDomainContext::reset();

    $this->worker = new QuantSeedWorker([], 'quant_seed_worker', []);
  }

  /**
   * Points the site at a given Quant project.
   *
   * @param string|null $project
   *   The project machine name.
   */
  protected function setActiveProject(?string $project) : void {
    \Drupal::configFactory()
      ->getEditable('quant_api.settings')
      ->set('api_project', $project)
      ->save();
  }

  /**
   * Builds a queue item that records whether it was sent.
   *
   * @param string|null $target
   *   The project the item is stamped for.
   *
   * @return \Drupal\quant\Plugin\QueueItem\QuantQueueItemInterface
   *   The recording item.
   */
  protected function recordingItem(?string $target) : QuantQueueItemInterface {
    return new class($target) implements QuantQueueItemInterface {

      /**
       * Whether send() ran.
       *
       * @var bool
       */
      public $sent = FALSE;

      /**
       * The stamped project.
       *
       * @var string|null
       */
      protected $target;

      /**
       * Constructs the item.
       */
      public function __construct(?string $target) {
        $this->target = $target;
      }

      /**
       * {@inheritdoc}
       */
      public function send() {
        $this->sent = TRUE;
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function info() {
        return 'recording item';
      }

      /**
       * {@inheritdoc}
       */
      public function log() {
        return 'recording item';
      }

      /**
       * {@inheritdoc}
       */
      public function getTargetProject() : ?string {
        return $this->target;
      }

    };
  }

  /**
   * An item is sent when its stamp matches the active project.
   *
   * @covers ::processItem
   * @covers ::targetsActiveProject
   */
  public function testSendsWhenProjectMatches() {
    $this->setActiveProject('project-a');
    $item = $this->recordingItem('project-a');

    $this->worker->processItem($item);

    $this->assertTrue($item->sent, 'The item was published.');
  }

  /**
   * An item is withheld when its stamp names a different project.
   *
   * This is the case that would otherwise put one client's page on another
   * client's domain.
   *
   * @covers ::processItem
   * @covers ::targetsActiveProject
   */
  public function testWithholdsWhenProjectDiffers() {
    $this->setActiveProject('project-a');
    $item = $this->recordingItem('project-b');

    try {
      $this->worker->processItem($item);
      $this->fail('A mismatched item should be requeued, not consumed.');
    }
    catch (DelayedRequeueException $e) {
      $this->assertGreaterThan(0, $e->getDelay(), 'The item is held before it can be claimed again.');
    }

    $this->assertFalse($item->sent, 'The item was not published to the wrong project.');
  }

  /**
   * A mismatched item is put back rather than dropped.
   *
   * A worker that returns normally has its item deleted. Declining to send
   * without requeueing would consume another domain's work, and that content
   * would never be published at all.
   *
   * @covers ::assertTargetsActiveProject
   */
  public function testMismatchedItemIsRequeuedNotConsumed() {
    $this->setActiveProject('project-a');

    $this->expectException(DelayedRequeueException::class);
    $this->worker->processItem($this->recordingItem('project-b'));
  }

  /**
   * Items queued before stamping existed are still sent.
   *
   * Queues survive a module update, and a single-domain site has no stamp to
   * compare, so an absent stamp must not block publishing.
   *
   * @covers ::targetsActiveProject
   */
  public function testSendsUnstampedLegacyItem() {
    $this->setActiveProject('project-a');
    $item = $this->recordingItem(NULL);

    $this->worker->processItem($item);

    $this->assertTrue($item->sent, 'An unstamped item was published.');
  }

  /**
   * An item is withheld when the worker has no project configured at all.
   *
   * @covers ::targetsActiveProject
   */
  public function testWithholdsWhenNoActiveProject() {
    $this->setActiveProject(NULL);
    $item = $this->recordingItem('project-a');

    try {
      $this->worker->processItem($item);
    }
    catch (DelayedRequeueException $e) {
      // Expected: held for a worker that knows where it belongs.
    }

    $this->assertFalse($item->sent, 'The item was not published without a target.');
  }

  /**
   * Anything that is not a queue item is ignored rather than fatal.
   *
   * @covers ::processItem
   */
  public function testIgnoresForeignQueueItem() {
    $this->assertNull($this->worker->processItem(new \stdClass()));
  }

  /**
   * A real queue item records the project configured when it was built.
   *
   * @covers \Drupal\quant\Plugin\QueueItem\TargetProjectTrait::getTargetProject
   */
  public function testRealItemStampsActiveProject() {
    $this->setActiveProject('project-a');

    $this->assertEquals('project-a', (new RouteItem(['route' => '/a']))->getTargetProject());
  }

  /**
   * The stamp follows the configuration in force at enqueue time.
   *
   * @covers \Drupal\quant\Plugin\QueueItem\TargetProjectTrait::stampTargetProject
   */
  public function testStampFollowsProjectAtEnqueueTime() {
    $this->setActiveProject('project-a');
    $first = new RouteItem(['route' => '/a']);

    $this->setActiveProject('project-b');
    $second = new RouteItem(['route' => '/b']);

    $this->assertEquals('project-a', $first->getTargetProject());
    $this->assertEquals('project-b', $second->getTargetProject());
  }

  /**
   * Without the Domain module, negotiation is a no-op.
   *
   * @covers \Drupal\quant\CliDomainContext::initialize
   */
  public function testDomainContextIsNoopWithoutDomainModule() {
    $this->assertFalse(\Drupal::moduleHandler()->moduleExists('domain'));
    $this->assertNull(CliDomainContext::initialize());
  }

  /**
   * The negotiated domain is resolved once and reused.
   *
   * Batch and loop callers invoke this per item, so it must not repeat the
   * config cache reset.
   *
   * @covers \Drupal\quant\CliDomainContext::initialize
   * @covers \Drupal\quant\CliDomainContext::reset
   */
  public function testDomainContextCachesItsResult() {
    $this->assertNull(CliDomainContext::initialize());
    $this->assertNull(CliDomainContext::initialize());

    CliDomainContext::reset();

    $this->assertNull(CliDomainContext::initialize());
  }

  /**
   * The active project is read through the overridable config factory.
   *
   * @covers \Drupal\quant\CliDomainContext::getActiveProject
   */
  public function testGetActiveProjectReadsConfig() {
    $this->setActiveProject('project-a');
    $this->assertEquals('project-a', CliDomainContext::getActiveProject());

    $this->setActiveProject(NULL);
    $this->assertNull(CliDomainContext::getActiveProject());
  }

}
