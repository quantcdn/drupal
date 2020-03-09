<?php

namespace Drupal\quant;

use Drupal\Core\Entity\EntityInterface;

/**
 * The Entity renderer interface object.
 */
interface EntityRendererInterface {

  /**
   * Returns a render markup string of a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity object.
   *
   * @return string
   *   The rendered entity.
   */
  public function render(EntityInterface $entity) : string;

}
