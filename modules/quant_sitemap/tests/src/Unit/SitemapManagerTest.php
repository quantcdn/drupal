<?php

namespace Drupal\Tests\quant_sitemap\Unit;

use Drupal\KernelTests\KernelTestBase;
use Drupal\quant_sitemap\SitemapManager;
use Drupal\simple_sitemap\Manager\EntityManager as SimpleSitemapManager;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandler;

/**
 * Test the collection subscriber.
 *
 * @group quant
 * @coversDefaultClass Drupal\quant_sitemap\SitemapManager
 */
class SitemapManagerTest extends KernelTestBase {

  /**
   * Verify quant sitemap compatibilty with xmlsitemap.
   */
  public function testSimpleSitemapSupportedVersion() {
    $module_handler_mock = $this->createMock(ModuleHandler::class);
    $module_handler_mock->expects($this->once())
      ->method('moduleExists')
      ->with('simple_sitemap')
      ->willReturn(TRUE);

    $module = new \StdClass();
    $module->info = ['version' => "4.1.6"];

    $module_list_mock = $this->createMock(ModuleExtensionList::class);
    $module_list_mock->expects($this->once())
      ->method('get')
      ->with('simple_sitemap')
      ->willReturn($module);

    $entity_manager_mock = $this->getMockBuilder(EntityTypeManager::class)
      ->disableOriginalConstructor()
      ->getMock();

    $manager = new SitemapManager($module_handler_mock, $entity_manager_mock, $module_list_mock);
    $result = $manager->isAvailable();
    $this->assertTrue($result[0]);
  }

  /**
   * Verify quant sitemap compatibilty with simple_sitemap.
   */
  public function testSimpleSitemapUnsupportedVersion() {
    $module_handler_mock = $this->createMock(ModuleHandler::class);
    $module_handler_mock->expects($this->any())
      ->method('moduleExists')
      ->willReturnMap([
        ['simple_sitemap', TRUE],
        ['xmlsitemap', FALSE],
      ]);

    $module = new \StdClass();
    $module->info = ['version' => "3.9"];

    $module_list_mock = $this->createMock(ModuleExtensionList::class);
    $module_list_mock->expects($this->once())
      ->method('get')
      ->with('simple_sitemap')
      ->willReturn($module);

    $entity_manager_mock = $this->getMockBuilder(EntityTypeManager::class)
      ->disableOriginalConstructor()
      ->getMock();

    $manager = new SitemapManager($module_handler_mock, $entity_manager_mock, $module_list_mock);
    $result = $manager->isAvailable();
    $this->assertFalse($result[0]);
  }

  /**
   * Verify quant sitemap compatibilty with xmlsitemap.
   */
  public function testXmlsitemapSupportedVersion() {
    $module_handler_mock = $this->createMock(ModuleHandler::class);
    $module_handler_mock->expects($this->any())
      ->method('moduleExists')
      ->willReturnMap([
        ['simple_sitemap', FALSE],
        ['xmlsitemap', TRUE],
      ]);

    $module = new \StdClass();
    $module->info = ['version' => "8.x-1.5"];

    $module_list_mock = $this->createMock(ModuleExtensionList::class);
    $module_list_mock->expects($this->once())
      ->method('get')
      ->with('xmlsitemap')
      ->willReturn($module);

    $entity_manager_mock = $this->getMockBuilder(EntityTypeManager::class)
      ->disableOriginalConstructor()
      ->getMock();

    $manager = new SitemapManager($module_handler_mock, $entity_manager_mock, $module_list_mock);
    $result = $manager->isAvailable();
    $this->assertTrue($result[0]);
  }

  /**
   * Verify quant sitemap compatibilty with xmlsitemap.
   */
  public function testXmlsitemapUnsupportedVersion() {
    $module_handler_mock = $this->createMock(ModuleHandler::class);
    $module_handler_mock->expects($this->any())
      ->method('moduleExists')
      ->willReturnMap([
        ['simple_sitemap', FALSE],
        ['xmlsitemap', TRUE],
      ]);

    $module = new \StdClass();
    $module->info = ['version' => "8.x-1.2"];

    $module_list_mock = $this->createMock(ModuleExtensionList::class);
    $module_list_mock->expects($this->once())
      ->method('get')
      ->with('xmlsitemap')
      ->willReturn($module);

    $entity_manager_mock = $this->getMockBuilder(EntityTypeManager::class)
      ->disableOriginalConstructor()
      ->getMock();

    $manager = new SitemapManager($module_handler_mock, $entity_manager_mock, $module_list_mock);
    $result = $manager->isAvailable();
    $this->assertFalse($result[0]);
  }

  /**
   * Test that if no sitemap module is available the module behaves correctly.
   */
  public function testSitemapUnavailable() {
    $module_handler_mock = $this->createMock(ModuleHandler::class);
    $module_handler_mock->expects($this->any())
      ->method('moduleExists')
      ->willReturnMap([
        ['simple_sitemap', FALSE],
        ['xmlsitemap', FALSE],
      ]);

    $module_list_mock = $this->createMock(ModuleExtensionList::class);
    $entity_manager_mock = $this->createMock(EntityTypeManager::class);

    $manager = $this->getMockBuilder(SitemapManager::class)
      ->setConstructorArgs([
        $module_handler_mock,
        $entity_manager_mock,
        $module_list_mock,
      ])
      ->setMethods(['isAvailable'])
      ->getMock();

    $manager->expects($this->once())
      ->method('isAvailable')
      ->willReturn([FALSE, '']);

    $result = $manager->getSitemaps();

    $this->assertEquals([], $result);

  }

  /**
   * Test that simple sitemap items generate as expected.
   */
  public function testSimpleSitemapListItems() {
    $module_handler_mock = $this->createMock(ModuleHandler::class);
    $module_handler_mock->expects($this->once())
      ->method('moduleExists')
      ->with('simple_sitemap')
      ->willReturn(TRUE);

    $module_list_mock = $this->createMock(ModuleExtensionList::class);
    $entity_manager_mock = $this->createMock(EntityTypeManager::class);

    $simplesitemap_manager = $this->createMock(SimpleSitemapManager::class);
    $simplesitemap_manager->expects($this->once())
      ->method('getAllBundleSettings')
      ->willReturn([
        'default' => [],
        'es' => [],
      ]);

    $manager = $this->getMockBuilder(SitemapManager::class)
      ->setConstructorArgs([
        $module_handler_mock,
        $entity_manager_mock,
        $module_list_mock,
      ])
      ->setMethods(['isAvailable', 'getSitemapManager'])
      ->getMock();

    $manager->expects($this->once())
      ->method('isAvailable')
      ->willReturn([TRUE, '']);

    $manager->expects($this->once())
      ->method('getSitemapManager')
      ->willReturn($simplesitemap_manager);

    $result = $manager->getSitemaps();

    $this->assertEquals([
      '/sitemap.xml',
      '/sitemap_generator/default/sitemap.xsl',
      '/es/sitemap.xml',
    ], $result);
  }

}
