<?php

namespace Drupal\quant_sitemap\EventSubscriber;

use Drupal\quant\Event\CollectRoutesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\simple_sitemap\SimplesitemapManager;

class CollectionSubscriber implements EventSubscriberInterface {

  /**
   * The entity type manager.
   */
  protected $manager;

  /**
   * The config factory.
   *
   * @var Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(SimplesitemapManager $sitemap_manager)
  {
    $this->manager = $sitemap_manager;
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
      \Drupal::service('messenger')->addMessage('no export sitemap');
      return;
    }

    $event->addRoute('/sitemap.xml');
    foreach ($this->manager->getSitemapVariants() as $variant => $def) {
      $event->addRoute("/$variant/sitemap.xml");
    }
  }

}
