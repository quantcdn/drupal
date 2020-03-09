<?php

namespace Drupal\quant\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Base class definition for Metadata.
 */
abstract class MetadataBase extends PluginBase implements MetadataInterface {

  // @TODO: Shared methods.

  /**
   * {@inheritdoc}
   */
  public function applies(EntityInterface $entity) : bool {
    return TRUE;
  }

}
