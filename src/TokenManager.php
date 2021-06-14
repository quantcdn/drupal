<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\quant\Exception\ExpiredTokenException;
use Drupal\quant\Exception\InvalidTokenException;
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
  }

  /**
   * Generate a token to use.
   *
   * @return string
   *   The token.
   */
  protected function generate() {
    if (function_exists('random_bytes')) {
      $bytes = random_bytes(ceil(16 / 2));
      $hash = substr(bin2hex($bytes), 0, 16);
    }
    else {
      $hash = bin2hex(random_bytes(16));
    }
    return base64_encode($hash);
  }

  /**
   * Delete a token.
   *
   * @param string $token
   *   The token to remove.
   */
  protected function delete($token) {
    $this->connection->delete('quant_token')
      ->condition('token', $token)
      ->execute();
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
    // @todo table has DEFAULT now() but this was causing
    // issues with request time mismatches so for now we just
    // insert the request time for create.
    $time = new \DateTime();
    $token = $this->generate();
    $query = $this->connection->insert('quant_token')
      ->fields([
        'route' => $route,
        'token' => $token,
        'created' => $time->getTimestamp(),
      ]);

    try {
      $query->execute();
    }
    catch (\Exception $error) {
      return FALSE;
    }

    return $token;
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

    $token = $this->request->getCurrentRequest()->headers->get('quant-token');
    $time = new \DateTime();
    $time = $time->getTimestamp();

    if (empty($token)) {
      return FALSE;
    }

    $query = $this->connection->select('quant_token', 'qt')
      ->condition('qt.token', $token)
      ->fields('qt', ['route', 'created'])
      ->range(0, 1);

    try {
      $record = $query->execute()->fetchObject();
    }
    catch (\Exception $error) {
      throw new InvalidTokenException($token, $time);
    }

    $valid_until = strtotime($this->settings->get('timeout'), $record->created);
    $expired = $time > $valid_until;

    if (!$strict && $expired) {
      throw new ExpiredTokenException($token, $time, $record);
    }

    if ($strict) {
      if ($expired || $route != $record->route) {
        throw new ExpiredTokenException($token, $time, $record);
      }
    }
  }

  /**
   * Release tokens that have been created.
   */
  public function release() {
    return $this->connection->query('TRUNCATE quant_token');
  }

}
