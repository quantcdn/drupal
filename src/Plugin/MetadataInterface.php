<?php

namespace Drupal\quant\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Defines the interface for the Quant Metadata.
 */
interface MetadataInterface extends PluginInspectionInterface {

  /**
   * Build the metadata value.
   *
   * @return array
   *   Build the metadata value.
   */
  public function build(EntityInterface $entity) : array;

  /**
   * If the metadata applies to the current entity.
   *
   * @return bool
   *   If the plugin applies.
   */
  public function applies(EntityInterface $entity) : bool;

}
