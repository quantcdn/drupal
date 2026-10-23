<?php

namespace Drupal\quant_api;

/**
 * Validates the Quant API endpoint setting.
 *
 * The client appends "/v1" to the saved value, so the setting must be the
 * bare origin, for example https://api.quantcdn.io.
 */
class EndpointValidator {

  /**
   * Validate an endpoint.
   *
   * @param string $endpoint
   *   The submitted endpoint.
   *
   * @return string|null
   *   An error identifier, or NULL when the endpoint is valid.
   */
  public static function validate($endpoint) {
    $endpoint = trim((string) $endpoint);
    $parts = parse_url($endpoint);

    if ($parts === FALSE || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
      return 'not_https';
    }
    if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])) {
      return 'not_origin';
    }
    return self::validatePath($parts['path'] ?? '');
  }

  /**
   * Validate the path part of an endpoint.
   */
  protected static function validatePath($path) {
    $path = rtrim($path, '/');
    if (preg_match('#(^|/)v1$#', $path)) {
      return 'has_v1';
    }
    return $path === '' ? NULL : 'not_origin';
  }

  /**
   * Normalise a valid endpoint: trim whitespace and trailing slashes.
   */
  public static function normalise($endpoint) {
    return rtrim(trim((string) $endpoint), '/');
  }

}
