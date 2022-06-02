<?php

namespace Drupal\quant\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Base class definition for Metadata.
 */
abstract class MetadataBase extends PluginBase implements MetadataInterface {

  /**
   * Configuration array.
   *
   * @var array
   */
  protected $config;

  /**
   * The default configuration.
   *
   * @return array
   *   The default configuration.
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * Build a configuration form.
   *
   * This allows some quant metadata plugins to accept configuration
   * values.
   *
   * @return array
   *   A valid form render array.
   */
  public function buildConfigurationForm() {
    return [];
  }

  /**
   * Set the configuration for the plugin instance.
   *
   * @param array $config
   *   The configuration array.
   *
   * @return object
   *   The class.
   */
  public function setConfiguration(array $config) {
    $this->config = $config;
    return $this;
  }

  /**
   * Get a plugins configuration value.
   *
   * @param string $key
   *   The config key.
   *
   * @return string
   *   The configuration value.
   */
  public function getConfig($key = '') {
    $default = $this->defaultConfiguration();
    return $this->config[$key] ?? $default[$key];
  }

  /**
   * {@inheritdoc}
   */
  public function applies(EntityInterface $entity) : bool {
    return TRUE;
  }

}
