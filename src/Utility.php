<?php

namespace Drupal\quant;

/**
 * Quant utility class for helper functions.
 */
class Utility {

  /**
   * Checks if it's an external URL.
   *
   * @param string $url
   *   The URL.
   *
   * @return boolean
   *   TRUE if external and FALSE otherwise.
   */
  public static function isExternalURL($url) {
    $config = \Drupal::config('quant.settings');
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $check_url = parse_url($url);
    if (isset($check_url['host']) && $check_url['host'] !== $hostname) {
      return TRUE;
    }
    return FALSE;
  }

}
