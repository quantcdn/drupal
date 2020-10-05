<?php

namespace Drupal\quant\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\quant\Form\MetadataConfigForm;

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

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    $plugin = parent::createInstance($plugin_id, $configuration);

    $config = \Drupal::config(MetadataConfigForm::SETTINGS)->get($plugin_id) ?: [];
    $plugin->setConfiguration($config);

    return $plugin;
  }

}
