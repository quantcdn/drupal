<?php

namespace Drupal\quant_webform;

/**
 * Tells a Quant export render from a live Drupal render.
 *
 * Quant fetches each page with a "quant-token" header, which the quant
 * module validates on kernel.request (an invalid token never reaches a
 * controller). Only that render gets the static form behaviour: the live
 * Drupal form keeps working as before.
 */
final class ExportContext {

  /**
   * Whether the current request is a Quant export render.
   */
  public static function isExport(): bool {
    $request = \Drupal::requestStack()->getCurrentRequest();
    return $request !== NULL && $request->headers->has('quant-token');
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
    return $webform !== NULL && $webform->getThirdPartySetting('quant_webform', 'enabled', TRUE);
  }

}
