<?php

namespace Drupal\quant_sitemap\EventSubscriber;

use Drupal\quant\Event\CollectRoutesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\quant_sitemap\SitemapManager;

/**
 * Collection subscriber for Sitemap routes.
 */
class CollectionSubscriber implements EventSubscriberInterface {

  /**
   * The core module handler.
   *
   * @var Drupal\quant_sitemap\SitemapManager
   */
  protected $manager;

  /**
   * The entity type manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   * @todo Remove this?
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(SitemapManager $manager) {
    $this->manager = $manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantCollectionEvents::ROUTES][] = ['collectRoutes'];
    return $events;
  }

  /**
   * Collect the sitemap routes.
   */
  public function collectRoutes(CollectRoutesEvent $event) {
    if (empty($event->getFormState()->getValue('export_sitemap'))) {
      return;
    }
    foreach ($this->manager->getSitemaps() as $route) {
      $event->queueItem(['route' => $route]);
    }
  }

}
