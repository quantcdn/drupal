<?php

namespace Drupal\quant\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\Plugin\QuantMetadataManager;
use Drupal\quant\Seed;

/**
 * Logs the creation of a new node.
 */
class NodeInsertSubscriber implements EventSubscriberInterface {

  /**
   * The metadata manager.
   *
   * @var Drupal\quant\Plugin\QuantMetadataManager
   */
  protected $metadataManager;

  /**
   * Constructs a node insertion event object.
   *
   * @param \Drupal\quant\Plugin\QuantMetadataManager $metadata_manager
   *   The metadata plugin manager.
   */
  public function __construct(QuantMetadataManager $metadata_manager) {
    $this->metadataManager = $metadata_manager;
  }

  /**
   * Log the creation of a new node.
   *
   * @param \Drupal\quant\Event\NodeInsertEvent $event
   *   The event interface.
   */
  public function onNodeInsert(NodeInsertEvent $event) {
    $entity = $event->getEntity();
    $langcode = $event->getLangcode();

    Seed::seedNode($entity, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[NodeInsertEvent::NODE_INSERT_EVENT][] = ['onNodeInsert'];
    return $events;
  }

}
