<?php

namespace Drupal\quant\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\EntityRendererInterface;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Plugin\QuantMetadataManager;

/**
 * Logs the creation of a new node.
 */
class NodeInsertSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a node insertion demo event object.
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
    $markup = $this->renderer->render($entity);
    $rid = $entity->get('vid')->value;
    $meta = [];

    foreach ($this->metadataManager->getDefinitions() as $pid => $def) {
      $plugin = $this->metadataManager->createInstance($pid);
      if ($plugin->applies($entity)) {
        $meta = array_merge($meta, $plugin->build($entity));
      }
    }

    // This should get the entity alias.
    $url = $entity->toUrl()->toString();

    // @todo: Special case for homepage (submit 2x, one for '/' and one for alias).
    // @todo: Special case for 404/403/error pages.
    \Drupal::service('event_dispatcher')->dispatch(QuantEvent::OUTPUT, new QuantEvent($markup, $url, $entity, $meta, $rid));

  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[NodeInsertEvent::NODE_INSERT_EVENT][] = ['onNodeInsert'];
    return $events;
  }
}
