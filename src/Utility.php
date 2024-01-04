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
   * @return bool
   *   TRUE if external and FALSE otherwise.
   */
  public static function isExternalUrl($url) {
    $config = \Drupal::config('quant.settings');
    $hostname = $config->get('host_domain') ?: $_SERVER['SERVER_NAME'];
    $check_url = parse_url($url);
    if (isset($check_url['host']) && $check_url['host'] !== $hostname) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Checks if an item is in a list which may use regular expressions.
   *
   * @param string $item
   *   The item to check for.
   * @param array $list
   *   The list to check. Items in the list can use regex.
   *
   * @return bool
   *   TRUE if the item is in the list and FALSE otherwise.
   */
  public static function inList($item, array $list) {
    $found = FALSE;
    foreach (array_filter($list) as $needle) {
      $pattern = preg_quote($needle, '/');
      $pattern = str_replace('\*', '.*', $pattern);
      preg_match('/^(' . $pattern . ')/', $item, $is_match);
      if (!empty($is_match)) {
        $found = TRUE;
      }
    }

    return $found;
  }

}
