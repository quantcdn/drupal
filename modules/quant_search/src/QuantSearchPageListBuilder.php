<?php

namespace Drupal\quant_search;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of Quant Search page.
 */
class QuantSearchPageListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Quant Search pages');
    $header['id'] = $this->t('Machine name');
    $header['enabled'] = $this->t('Enabled');
    $header['route'] = $this->t('Route');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['enabled'] = $entity->status();
    $row['route'] = $entity->get('route');
    return $row + parent::buildRow($entity);
  }

}
