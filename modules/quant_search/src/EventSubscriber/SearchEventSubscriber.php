<?php

namespace Drupal\quant_search\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\quant\Event\QuantEvent;

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

    $config = \Drupal::config('quant_search.entities.settings');
    $entity = $event->getEntity();
    $entityType = $entity->getEntityTypeId();

    // Get token values from context.
    $ctx = [];
    $ctx[$entityType] = $entity;

    $title = \Drupal::token()->replace($config->get('quant_search_title_token'), $ctx);
    $summary = \Drupal::token()->replace($config->get('quant_search_summary_token'), $ctx);

    $view_builder = \Drupal::entityTypeManager()->getViewBuilder($entityType);
    $view_mode = $config->get('quant_search_content_viewmode');
    $build = $view_builder->view($entity, $view_mode);
    $output = render($build);

    $meta = $event->getMetadata();
    $meta['search_record']['title'] = $title;
    $meta['search_record']['summary'] = strip_tags($summary);
    $meta['search_record']['content'] = strip_tags($output);
    $event->setMetadata($meta);
  }

}
