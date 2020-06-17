<?php

namespace Drupal\quant\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines the metadata item annotation object.
 *
 * @see \Drupal\quant\Plugin\MetadataInterface
 * @see plugin_api
 *
 * @Annotation
 */
class Metadata extends Plugin {

  /**
   * The metadata id.
   *
   * @var string
   */
  public $id;

  /**
   * The metadata label.
   *
   * @var string
   */
  public $label;


  /**
   * The plugin description.
   *
   * @var string
   */
  public $description;

}
