<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The quant file system event listener.
 */
class QuantFilesystem implements EventSubscriberInterface {

  /**
   * The storage location.
   *
   * @TODO: This should be configurable.
   */
  const LOC = DRUPAL_ROOT . '/../html';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantEvent::OUTPUT][] = ['onOutput'];
    return $events;
  }

  /**
   * Store the markup in the local file system.
   *
   * @param \Drupal\quant\Event\QuantEvent $event
   *   The event that is being triggered.
   */
  public function onOutput(QuantEvent $event) {

    // @todo: Remove local file writing, handled by API.
    return;

    $dir = self::LOC . dirname($event->getLocation());
    $file = self::LOC . $event->getLocation();
    // Make sure the output directory exists.
    @mkdir($dir, 0755);
    file_put_contents($file, $event->getContents());
  }

}
