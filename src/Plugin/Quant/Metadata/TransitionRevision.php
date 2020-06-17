<?php

namespace Drupal\quant\Plugin\Quant\Metadata;

use Drupal\Core\Entity\EntityInterface;
use Drupal\quant\Plugin\MetadataBase;

/**
 * Handle the transitions metadata.
 *
 * @Metadata(
 *  id = "transition_revision",
 *  label = @Translation("Transitions"),
 *  description = @Translation("")
 * )
 */
class TransitionRevision extends MetadataBase {

  /**
   * {@inheritdoc}
   */
  public function applies(EntityInterface $entity): bool {
    return $entity->getEntityType()->isRevisionable();
  }

  /**
   * {@inheritdoc}
   */
  public function build(EntityInterface $entity): array {
    return ['transition_revision' => $entity->get('vid')->value];
  }

}
