<?php

namespace Drupal\quant_purger\Entity;

/**
 * Helper class that centralizes string hashing for security and maintenance.
 */
class Hash {

  /**
   * Create a hash with the given input and length.
   *
   * @param string $input
   *   The input string to be hashed.
   * @param int $length
   *   The length of the hash.
   *
   * @return string
   *   Cryptographic hash with the given length.
   */
  protected static function hashInput($input, $length) {
    // MD5 is the fastest algorithm beyond CRC32 (which is 30% faster, but high
    // collision risk), so this is the best bet for now. If collisions are going
    // to be a major problem in the future, we might have to consider a hash DB.
    $hex = md5($input);
    // The produced HEX can be converted to BASE32 number to take less space.
    // For example 5 characters HEX can be stored in 4 characters BASE32.
    $hash = base_convert(substr($hex, 0, ceil($length * 1.25)), 16, 32);
    // Return a hash with consistent length, padding zeroes if needed.
    return strtolower(str_pad(substr($hash, 0, $length), $length, '0', STR_PAD_LEFT));
  }

  /**
   * Create unique hashes/IDs for a list of cache tag strings.
   *
   * @param string[] $tags
   *   Non-associative array cache tags.
   *
   * @return string[]
   *   Non-associative array with hashed copies of the given cache tags.
   */
  public static function cacheTags(array $tags) {
    $hashes = [];
    foreach ($tags as $tag) {
      if (strlen($tag) > 4) {
        $hashes[] = self::hashInput($tag, 4);
      }
      else {
        $hashes[] = $tag;
      }
    }
    return $hashes;
  }

  /**
   * Create a unique hash that identifies this site.
   *
   * @param string $site_name
   *   The identifier of the site on QuantCDN.
   * @param string $site_path
   *   The path of the site, e.g. 'site/default' or 'site/database_a'.
   *
   * @return string
   *   Cryptographic hash that's long enough to be unique.
   */
  public static function siteIdentifier($site_name, $site_path) {
    return self::hashInput($site_name . $site_path, 16);
  }

}
