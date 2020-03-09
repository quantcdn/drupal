<?php

namespace Drupal\quant\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Provides the Quant metadata manager.
 */
class QuantMetadataManager extends DefaultPluginManager {

  /**
   * Constructs a QuantMetadataManager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Quant/Metadata',
      $namespaces,
      $module_handler,
      'Drupal\quant\Plugin\MetadataInterface',
      'Drupal\quant\Annotation\Metadata'
    );
    $this->alterInfo('quant_metadata_info');
    $this->setCacheBackend($cache_backend, 'quant_metadata_plugins');
  }

}
