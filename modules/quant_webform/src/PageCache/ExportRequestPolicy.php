<?php

namespace Drupal\quant_webform\PageCache;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps Quant export renders out of the page cache.
 *
 * The page cache keys on the URL only. Without this policy a live visit
 * could be served to Quant (no static form behaviour) or an export render
 * could be served to a live visitor (a form that posts to Quant).
 */
class ExportRequestPolicy implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    return $request->headers->has('quant-token') ? static::DENY : NULL;
  }

}
