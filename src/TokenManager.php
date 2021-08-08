<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\quant\Exception\ExpiredTokenException;
use Drupal\quant\Exception\InvalidTokenException;
use Drupal\quant\Exception\StrictTokenException;
use Drupal\quant\Exception\TokenValidationDisabledException;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Component\Datetime\TimeInterface;

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
   * Construct a TokenManager instance.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   The current request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time interface.
   */
  public function __construct(Connection $connection, RequestStack $request, ConfigFactoryInterface $config_factory, TimeInterface $time) {
    $this->connection = $connection;
    $this->request = $request;
    $this->settings = $config_factory->get('quant.token_settings');
    $this->time = $time;
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

    return str_replace(
      ['+', '/', '='],
      ['-', '_', ''],
      base64_encode($part)
    );
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
    $time = $this->time->getRequestTime();

    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = [
      'user' => 'quant',
      'route' => $route,
      'expires' => strtotime($this->settings->get('timeout'), $time),
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

    $secret = $this->settings->get('secret');
    $time = $this->time->getRequestTime();
    $token = $this->request->getCurrentRequest()->headers->get('quant-token');

    if (empty($token)) {
      throw new InvalidTokenException($token, $time);
    }

    $token_parts = explode('.', $token);
    $header = json_decode(base64_decode($token_parts[0]), TRUE);
    $payload = json_decode(base64_decode($token_parts[1]), TRUE);

    $provided_signature = $token_parts[2];

    $signature = hash_hmac('sha256', "{$token_parts[0]}.{$token_parts[1]}", $secret, TRUE);
    $signature = self::encode($signature);

    if ($signature !== $provided_signature) {
      throw new InvalidTokenException($token, $time);
    }

    if (empty($payload['expires'])) {
      throw new InvalidTokenException($token, $time);
    }

    if ($payload['expires'] - $time < 0) {
      throw new ExpiredTokenException($token, $payload['expires'], $time);
    }

    if ($strict && ($route != $payload['route'])) {
      throw new StrictTokenException($token, $payload['route'], $route);
    }

    return TRUE;
  }

}
