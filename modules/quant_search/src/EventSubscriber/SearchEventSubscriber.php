<?php

namespace Drupal\quant_search\EventSubscriber;

use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant_search\Controller\Search;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Inject search_record object during push.
 */
class SearchEventSubscriber implements EventSubscriberInterface {

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The event dispatcher.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * Search event subscriber.
   *
   * Listens to Quant events and updates search_record data.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, ContainerAwareEventDispatcher $event_dispatcher) {
    $this->logger = $logger_factory->get('quant_search');
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantEvent::OUTPUT] = ['onOutput', 1];
    return $events;
  }

  /**
   * Push search_record object based on configuration.
   *
   * @param Drupal\quant\Event\QuantEvent $event
   *   The event.
   */
  public function onOutput(QuantEvent $event) {
    $entity = $event->getEntity();
    $meta = $event->getMetadata();
    $langcode = $event->getLangcode();
    $meta['search_record'] = Search::generateSearchRecord($entity, $langcode);
    $event->setMetadata($meta);
  }

}
