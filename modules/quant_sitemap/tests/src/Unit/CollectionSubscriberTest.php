<?php

namespace Drupal\Tests\quant_sitemap\Unit;

use Drupal\Core\KeyValueStore\StorageBase;
use Drupal\Core\Language\Language;
use Drupal\Tests\UnitTestCase;
use Drupal\quant_sitemap\EventSubscriber\CollectionSubscriber;
use Drupal\simple_sitemap\SimplesitemapManager;
use Drupal\xmlsitemap\Entity\XmlSitemap;

/**
 * Test the collection subscriber.
 *
 * @group quant
 * @coversDefaultClass Drupal\quant_sitemap\EventSubscriber\CollectionSubscriber
 */
class CollectionSubscriberTest extends UnitTestCase {

  /**
   * Ensure that the XmlSitemapItems are generated correctly.
   */
  public function testGetXmlSitemapItems() {
    $stub = $this->prophesize(CollectionSubscriber::class);
    $storage = $this->prophesize(StorageBase::class);
    $entities = [];

    foreach (['en', 'es'] as $lang) {
      $l = $this->prophesize(Language::class);
      $l->getId()->willReturn($lang);
      $entity = $this->prophesize(XmlSitemap::class);
      $entity->language()->willReturn($l);
      $entities[] = $entity;
    }

    $storage->loadMultiple()->willReturn($entities);
    $stub->getEntityTypeManager()->willReturn($storage);

    $this->assertEquals([
      '/en/sitemap.xml',
      '/es/sitemap.xml',
    ], $stub->getXmlsitemapItems());
  }

  /**
   * Ensure simple sitemap items are generated.
   */
  public function testGetSimpleSitemapItems() {
    $sitemap_manager = $this->prophesize(SimplesitemapManager::class);
    $variants = [
      'default' => [],
      'sample' => [],
    ];
    $sitemap_manager->getSitemapVariants()->willReturn($variants);

    $stub = $this->prophesize(CollectionSubscriber::class);
    $stub->getSitemapManager()->willReturn($sitemap_manager);

    $this->assertEquals([
      '/sitemap.xml',
      '/default/sitemap.xml',
      '/sample/sitemap.xml',
    ], $stub->getSimpleSitemapItems());
  }

}
