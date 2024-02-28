<?php

namespace Drupal\quant_sitemap;

use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Sitemap manager for Quant Sitemap.
 */
class SitemapManager {

  use StringTranslationTrait;

  const SIMPLE_SITEMAP_MINIMUM_VERSION = "4.1.6";

  const XMLSITEMAP_MINIMUM_VERSION = "8.x-1.5";

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
   * The list of enabled modules.
   *
   * @var Drupal\Core\Extension\ModuleExtensionList
   */
  protected $moduleList;

  /**
   * Construct the SitemapManager.
   */
  public function __construct(ModuleHandler $module_handler, EntityTypeManager $entity_type_manager, ModuleExtensionList $modules) {
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleList = $modules;
  }

  /**
   * Getter for module handler property.
   *
   * @return Drupal\Core\Extension\ModuleHandler
   *   The module handler.
   */
  public function getModuleHandler() {
    return $this->moduleHandler;
  }

  /**
   * Getter for module list.
   *
   * @return Drupal\Core\Extension\ModuleExtensionList
   *   The module extension list.
   */
  public function getModuleList() {
    return $this->moduleList;
  }

  /**
   * Getter for entity type manager.
   *
   * @return Drupal\Core\Entity\EntityTypeManager
   *   The entity type manager.
   */
  public function getEntityTypeManager() {
    return $this->entityTypeManager;
  }

  /**
   * Determine if the sitemap integration is available for this site.
   *
   * @return array
   *   The status and a reason for it not being available.
   */
  public function isAvailable() : array {
    $reason = $this->t('quant_sitemap requires simple_sitemap or xmlsitemap');

    if ($this->getModuleHandler()->moduleExists('simple_sitemap')) {
      $module = $this->getModuleList()->get('simple_sitemap');
      if (!empty($module) && version_compare($module->info['version'], self::SIMPLE_SITEMAP_MINIMUM_VERSION, '>=')) {
        return [
          TRUE,
          $this->t('simple_sitemap is installed at a supported version (@ver)', [
            '@ver' => $module->info['version'],
          ]),
        ];
      }
      else {
        $reason = $this->t('quant_sitemap requires simple_sitemap to be version >= @ver', [
          '@ver' => self::SIMPLE_SITEMAP_MINIMUM_VERSION,
        ]);
      }
    }

    if ($this->moduleHandler->moduleExists('xmlsitemap')) {
      $module = $this->moduleList->get('xmlsitemap');
      if (!empty($module) && version_compare($module->info['version'], self::XMLSITEMAP_MINIMUM_VERSION, '>=')) {
        return [
          TRUE,
          $this->t('xmlsitemap is installed at a supported version (@ver)', [
            '@ver' => $module->info['version'],
          ]),
        ];
      }
      else {
        $reason = $this->t('quant_sitemap requires xmlsitemap to be version >= @ver', [
          '@ver' => self::XMLSITEMAP_MINIMUM_VERSION,
        ]);
      }
    }

    return [FALSE, $reason];
  }

  /**
   * Get configured sitemaps based on available sources.
   *
   * @return array
   *   A list of sitemap paths.
   */
  public function getSitemaps() : array {
    [$available] = $this->isAvailable();
    if (!$available) {
      return [];
    }

    if ($this->getModuleHandler()->moduleExists('simple_sitemap')) {
      return $this->getSimpleSitemaps();
    }

    if ($this->getModuleHandler()->moduleExists('xmlsitemap')) {
      return $this->getXmlSitemaps();
    }

    return [];
  }

  /**
   * Get the simple sitemap manager.
   *
   * @return Drupal\simple_sitemap\Manager\EntityManager
   *   The sitemap manager.
   */
  protected function getSitemapManager() {
    return \Drupal::service('simple_sitemap.entity_manager');
  }

  /**
   * Get sitemap items from simple_sitemap.
   *
   * @return array
   *   A list of sitemap items.
   */
  protected function getSimpleSitemaps() : array {

    // Gather XML and XSL files.
    $items = [];
    foreach ($this->getSitemapManager()->getSitemaps() as $id => $sitemap) {

      // Only add enabled sitemaps with content.
      if ($sitemap->isEnabled() && $sitemap->contentStatus()) {

        // Note, creating an options array with 'absolute' => FALSE does not
        // generate a relative URL, so we have to extract it.
        $items[] = parse_url($sitemap->toUrl()->toString())['path'];

        // Figure out the XSL file for this sitemap.
        $pluginId = $sitemap->getType()->getSitemapGenerator()->getPluginId();
        $items[] = '/sitemap_generator/' . $pluginId . '/sitemap.xsl';
      }
    }

    // Remove duplicate XSL files.
    $items = array_unique($items);

    return $items;
  }

  /**
   * Get sitemap items from xmlsitemap.
   *
   * @return array
   *   A list of sitemap paths.
   */
  protected function getXmlSitemaps() : array {
    $items = ['/sitemap.xml', '/sitemap.xsl'];
    $sitemaps = $this->getEntityTypeManager()->getStorage('xmlsitemap')->loadMultiple();
    $lang_code = \Drupal::service('language.default')->get()->getId();

    foreach ($sitemaps as $sitemap) {
      $context = $sitemap->getContext();
      if (empty($context['language']) || $context['language'] == $lang_code) {
        // Default langcode is always served via sitemap.xml as a hard coded
        // link from xmlsitemap and this will result in a 404 — so we just
        // skip the default langauge for now.
        continue;
      }
      $items[] = "/{$context['language']}/sitemap.xml";
    }
    return $items;
  }

}
