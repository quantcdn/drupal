<?php

namespace Drupal\quant_webform;

use Symfony\Component\HttpFoundation\Request;

/**
 * Tells a Quant export render from a live Drupal render.
 *
 * Quant fetches each page with a signed "quant-token" header. Only a render
 * with a genuine token gets the static form behaviour: the live Drupal form
 * keeps working as before.
 */
final class ExportContext {

  /**
   * Whether the current request is a Quant export render.
   */
  public static function isExport(): bool {
    $request = \Drupal::requestStack()->getCurrentRequest();
    return $request !== NULL && self::isExportRequest($request);
  }

  /**
   * Whether a request carries a genuine export token.
   *
   * Takes the request, so the page cache policy can use it before the
   * request is on the request stack.
   */
  public static function isExportRequest(Request $request): bool {
    if (!$request->headers->has('quant-token')) {
      return FALSE;
    }
    // Only a token this site signed counts: with content drafts off the
    // token is not validated on kernel.request, so the header alone proves
    // nothing.
    if (!$request->attributes->has('_quant_export')) {
      $request->attributes->set('_quant_export', \Drupal::service('quant.token_manager')->isGenuine($request->headers->get('quant-token'), $request->getPathInfo()));
    }
    return (bool) $request->attributes->get('_quant_export');
  }

  /**
   * Whether this is an export render of a Webform registered with Quant.
   *
   * @param string|null $webform_id
   *   The Webform id.
   */
  public static function isStaticForm(?string $webform_id): bool {
    if (!self::isExport() || !$webform_id) {
      return FALSE;
    }
    $webform = \Drupal::entityTypeManager()->getStorage('webform')->load($webform_id);
    return $webform !== NULL && QuantFormsRegistry::isOptedIn($webform);
  }

}
