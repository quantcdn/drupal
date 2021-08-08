<?php

namespace Drupal\quant_sitemap\EventSubscriber;

use Drupal\quant\Event\CollectRoutesEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Entity\EntityTypeManager;

/**
 * Collection subscriber for Sitemap routes.
 */
class CollectionSubscriber implements EventSubscriberInterface {

  /**
   * The core module handler.
   *
   * @var Drupal\Core\Extension\ModuleHandler
   */
  protected $moduleHandler;

  /**
   * The entity type manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(ModuleHandler $module_handler, EntityTypeManager $entity_type_manager) {
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantCollectionEvents::ROUTES][] = ['collectRoutes'];
    return $events;
  }

  /**
   * Get the simple sitemap manager.
   *
   * @return Drupal\simple_sitemap\SimplesitemapManager
   *   The sitemap manager.
   */
  public function getSitemapManager() {
    return \Drupal::service('simple_sitemap.manager');
  }

  /**
   * The entity type manager.
   *
   * @return Drupal\Core\Entity\EntityTypeManager
   *   The entity type manager.
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * Simple sitemap support.
   *
   * Simple sitemap allows you to define accessible sitemap routes. This
   * is always prefixed by the variant ID. As this is not a standard
   * content entity we need to use the provided manager to load the values.
   *
   * @return array
   *   A list of routes that sitemaps are accessible by.
   */
  public function getSimpleSitemapItems() : array {
    $items = ['/sitemap.xml'];
    foreach ($this->getSitemapManager()->getSitemapVariants() as $variant => $def) {
      $items[] = "/$variant/sitemap.xml";
    }
    return $items;
  }

  /**
   * XMLSitemap support.
   *
   * XMLSitemap only allows base level sitemaps with language prefixes.
   * Each enabled language context can only have one sitemap, the admin
   * UI returns an error if you try to double up.
   *
   * @return array
   *   A list of routes that sitemaps are accessible by.
   */
  public function getXmlsitemapItems() : array {
    $items = [];
    $sitemaps = $this->getEntityTypeManager()->getStorage('xmlsitemap')->loadMultiple();
    foreach ($sitemaps as $sitemap) {
      $items[] = "/{$sitemap->language()->getId()}/sitemap.xml";
    }
    return $items;
  }

  /**
   * Collect the sitemap routes.
   */
  public function collectRoutes(CollectRoutesEvent $event) {
    if (empty($event->getFormState()->getValue('export_sitemap'))) {
      return;
    }

    $items = [];

    if ($this->moduleHandler->moduleExists('simple_sitemap')) {
      $items = $this->getSimpleSitemapItems();
    }
    elseif ($this->moduleHandler->moduleExists('xmlsitemap')) {
      $items = $this->getXmlsitemapItems();
    }

    foreach ($items as $route) {
      $event->queueItem(['route' => $route]);
    }
  }

}
