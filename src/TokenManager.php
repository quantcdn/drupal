<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\quant\Exception\ExpiredTokenException;
use Drupal\quant\Exception\InvalidTokenException;
use Drupal\quant\Exception\StrictTokenException;
use Drupal\quant\Exception\TokenValidationDisabledException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Simple interface to manage short-lived access tokens.
 *
 * @ingroup quant
 */
class TokenManager {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $request;

  /**
   * Token configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * Global Quant configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $quantSettings;

  /**
   * Construct a TokenManager instance.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   The current request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(Connection $connection, RequestStack $request, ConfigFactoryInterface $config_factory) {
    $this->connection = $connection;
    $this->request = $request;
    $this->settings = $config_factory->get('quant.token_settings');
    $this->quantSettings = $config_factory->get('quant.settings');
  }

  /**
   * Prepare a JWT header part.
   *
   * @param array|string $part
   *   The part to encode.
   *
   * @return string
   *   base64encoded string.
   */
  public static function encode($part = []) {
    if (is_array($part)) {
      $part = json_encode($part);
    }
    $encoded = base64_encode($part);

    if ($encoded === FALSE) {
      throw new \Exception('Unable to encode part.');
    }

    $encoded = strtr($encoded, '+/', '-_');
    return rtrim($encoded, '=');
  }

  /**
   * Reverse the JWT decode.
   *
   * @param string $string
   *   The encoded string.
   * @param bool $strict
   *   Base64 in strict mode.
   *
   * @return string|array
   *   The decoded URL part.
   */
  public static function decode($string, $strict = FALSE) {
    $string = strtr($string, '-_', '+/');
    $part = base64_decode($string, $strict);
    $array_part = json_decode($part, TRUE);
    return empty($array_part) ? $part : $array_part;
  }

  /**
   * Create a token for the node.
   *
   * @param string $route
   *   A route to reference for the token.
   *
   * @return string
   *   The token.
   */
  public function create($route = NULL) {
    $secret = $this->settings->get('secret');
    $time = new \DateTime($this->settings->get('timeout'));

    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = [
      'user' => 'quant',
      'route' => $route,
      'expires' => $time->format('U'),
    ];

    $header = self::encode($header);
    $payload = self::encode($payload);
    $signature = hash_hmac('sha256', "$header.$payload", $secret, TRUE);

    return "$header.$payload." . self::encode($signature);
  }

  /**
   * Validate the token from the request.
   *
   * This method infers the token from the current request context
   * and will attempt to validate that the token is able to provide
   * valid access.
   *
   * @param string $route
   *   The route to validate.
   * @param bool $strict
   *   If we should validate the entity_id in the check.
   *
   * @return bool
   *   If the token is valid.
   *
   * @throws Drupal\quant\Exception\InvalidTokenException
   * @throws Drupal\quant\Exception\ExpiredTokenException
   */
  public function validate($route = NULL, $strict = TRUE) {
    if ($this->settings->get('disable')) {
      // Allow administrators to completely bypass the token verification
      // process. This can be done to test server configuration and is
      // not recommended in production.
      throw new TokenValidationDisabledException();
    }

    if ($this->quantSettings->get('disable_content_drafts')) {
      // When content drafts are disabled the token is irrelevant. It may not
      // even be included in the internal HTTP request. Bypass validation
      // altogether, as the token is only required for draft access.
      throw new TokenValidationDisabledException();
    }

    $secret = $this->settings->get('secret');
    $current_time = new \DateTime();
    $token = $this->request->getCurrentRequest()->headers->get('quant-token');

    if (empty($token)) {
      throw new InvalidTokenException($token, $current_time->format('U'));
    }

    $token_parts = explode('.', $token);
    $header = self::decode($token_parts[0]);
    $payload = self::decode($token_parts[1]);

    $provided_signature = $token_parts[2];

    $signature = hash_hmac('sha256', "{$token_parts[0]}.{$token_parts[1]}", $secret, TRUE);
    $signature = self::encode($signature);

    if ($signature !== $provided_signature) {
      throw new InvalidTokenException($token, $current_time->format('U'));
    }

    if (empty($payload['expires'])) {
      throw new InvalidTokenException($token, $current_time->format('U'));
    }

    $request_time = new \DateTime();
    $request_time->setTimestamp($payload['expires']);
    $date_diff = $current_time->diff($request_time);

    // The %r format will return empty string or '-' if the diff is
    // in the past, we can use this to determine if the date diff
    // is negative and restrict access accordingly.
    // @see https://www.php.net/manual/en/dateinterval.format.php
    if (!empty($date_diff->format('%r'))) {
      throw new ExpiredTokenException($token, $payload['expires'], $current_time->format('U'));
    }

    // Ensure both routes are decoded for consistent comparison.
    $route = urldecode($route);
    $payload_route = urldecode($payload['route']);

    if ($strict && (parse_url($route, PHP_URL_PATH) != parse_url($payload_route, PHP_URL_PATH))) {
      throw new StrictTokenException($token, $payload['route'], $route);
    }

    return TRUE;
  }

}
