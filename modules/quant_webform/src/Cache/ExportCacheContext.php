<?php

namespace Drupal\quant_webform\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\quant_webform\ExportContext;

/**
 * Cache context "quant_export": two values, export or live.
 *
 * The header context varied by the signed token, which changes on every
 * fetch, so every export render was a cache miss and a new row.
 */
class ExportCacheContext implements CacheContextInterface {

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Quant export or live render');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    return ExportContext::isExport() ? 'export' : 'live';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    return new CacheableMetadata();
  }

}
