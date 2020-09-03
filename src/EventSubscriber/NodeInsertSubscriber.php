<?php

namespace Drupal\quant\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\EntityRendererInterface;
use Drupal\quant\Plugin\QuantMetadataManager;
use Drupal\quant\Seed;

/**
 * Logs the creation of a new node.
 */
class NodeInsertSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a node insertion event object.
   *
   * @param \Drupal\quant\EntityRendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\quant\Plugin\QuantMetadataManager $metadata_manager
   *   The metadata plugin manager.
   */
  public function __construct(EntityRendererInterface $renderer, QuantMetadataManager $metadata_manager) {
    $this->renderer = $renderer;
    $this->metadataManager = $metadata_manager;
  }

  /**
   * Log the creation of a new node.
   *
   * @param \Drupal\quant\Event\NodeInsertEvent $event
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
