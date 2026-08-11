<?php

namespace Drupal\Tests\quant\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\EventSubscriber\DomainGuardSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Ensures content is not published from a host with no domain record.
 *
 * @coversDefaultClass \Drupal\quant\EventSubscriber\DomainGuardSubscriber
 *
 * @group quant
 */
class DomainGuardSubscriberTest extends UnitTestCase {

  /**
   * Builds a container and returns the subscriber under test.
   *
   * @param bool $domainEnabled
   *   Whether the Domain module is installed.
   * @param array $domains
   *   Domain records that exist, keyed by id.
   * @param string|null $matchedHost
   *   The host that resolves to a domain, or NULL for none.
   * @param string $requestHost
   *   The host the request arrived on.
   *
   * @return \Drupal\quant\EventSubscriber\DomainGuardSubscriber
   *   The subscriber.
   */
  protected function subscriber(bool $domainEnabled, array $domains = [], ?string $matchedHost = NULL, string $requestHost = 'clientb.example') : DomainGuardSubscriber {
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturnCallback(
      fn($name) => $name === 'domain' ? $domainEnabled : FALSE
    );

    // loadByHostname() belongs to the Domain module's storage handler, which
    // is not present here, so it is added to the double explicitly.
    $storage = $this->getMockBuilder(EntityStorageInterface::class)
      ->addMethods(['loadByHostname'])
      ->getMockForAbstractClass();

    $storage->method('loadMultiple')->willReturn($domains);
    $storage->method('loadByHostname')->willReturnCallback(
      fn($host) => $host === $matchedHost ? (object) ['id' => 'matched'] : NULL
    );

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->willReturn($storage);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('project-default');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->createMock(LoggerChannelInterface::class));

    $container = new ContainerBuilder();
    $container->set('module_handler', $moduleHandler);
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('config.factory', $configFactory);
    $container->set('logger.factory', $loggerFactory);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $stack = new RequestStack();
    $stack->push(Request::create('http://' . $requestHost . '/node/1'));

    return new DomainGuardSubscriber($stack);
  }

  /**
   * Builds an output event for a page.
   *
   * @return \Drupal\quant\Event\QuantEvent
   *   The event.
   */
  protected function event() : QuantEvent {
    return new QuantEvent('<html></html>', '/node/1', [], NULL);
  }

  /**
   * The guard runs ahead of the search and publish subscribers.
   *
   * Stopping propagation only prevents the push if this runs first.
   *
   * @covers ::getSubscribedEvents
   */
  public function testGuardRunsBeforePublishing() {
    $events = DomainGuardSubscriber::getSubscribedEvents();

    $this->assertGreaterThan(1, $events[QuantEvent::OUTPUT][1]);
  }

  /**
   * A site without the Domain module publishes as normal.
   *
   * @covers ::onOutput
   */
  public function testPublishesWithoutDomainModule() {
    $event = $this->event();
    $this->subscriber(FALSE)->onOutput($event);

    $this->assertFalse($event->isPropagationStopped());
  }

  /**
   * A site with the module but no domains configured publishes as normal.
   *
   * @covers ::onOutput
   */
  public function testPublishesWhenNoDomainsConfigured() {
    $event = $this->event();
    $this->subscriber(TRUE, [])->onOutput($event);

    $this->assertFalse($event->isPropagationStopped());
  }

  /**
   * A host that resolves to a domain publishes as normal.
   *
   * @covers ::onOutput
   */
  public function testPublishesWhenHostResolves() {
    $event = $this->event();
    $this->subscriber(TRUE, ['clientb' => 'x'], 'clientb.example', 'clientb.example')
      ->onOutput($event);

    $this->assertFalse($event->isPropagationStopped());
  }

  /**
   * A host with no domain record stops the push.
   *
   * This is the case where the Domain module silently falls back to the
   * default domain and the content would reach another client's project.
   *
   * @covers ::onOutput
   * @covers ::hostIsUnknown
   */
  public function testStopsWhenHostHasNoDomain() {
    $event = $this->event();
    $this->subscriber(TRUE, ['clienta' => 'x'], 'clienta.example', 'unregistered.example')
      ->onOutput($event);

    $this->assertTrue($event->isPropagationStopped());
  }

  /**
   * The port is part of the host, so a port mismatch also stops the push.
   *
   * @covers ::hostIsUnknown
   */
  public function testPortMismatchStopsPush() {
    $event = $this->event();
    $this->subscriber(TRUE, ['clientb' => 'x'], 'clientb.example:8080', 'clientb.example')
      ->onOutput($event);

    $this->assertTrue($event->isPropagationStopped());
  }

}
