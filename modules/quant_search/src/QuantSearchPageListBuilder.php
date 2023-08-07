<?php

namespace Drupal\quant_search;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Provides a listing of Quant Search Page entities.
 */
class QuantSearchPageListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Quant Search Page');
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
    $row['enabled'] = $entity->status() ? $this->t('Yes') : $this->t('No');
    $row['route'] = Link::fromTextAndUrl($entity->get('route'), Url::fromUri('internal:/' . $entity->get('route'), ['absolute' => TRUE]));
    return $row + parent::buildRow($entity);
  }

}
