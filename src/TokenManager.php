<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
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
    $time = $this->request->getCurrentRequest()->server->get('REQUEST_TIME');
    $token = $this->generate();
    $query = $this->connection->insert('quant_token')
      ->fields([
        'route' => $route,
        'token' => $token,
        'created' => date('Y-m-d H:i:s', $time),
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
   */
  public function validate($route = NULL, $strict = TRUE) {

    $token = $this->request->getCurrentRequest()->headers->get('quant-token');
    $time = $this->request->getCurrentRequest()->server->get('REQUEST_TIME');

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
      return FALSE;
    }

    $valid_until = strtotime($this->settings->get('timeout'), strtotime($record->created));

    if (!$strict) {
      // Ensure the token is valid.
      return $time < $valid_until;
    }

    // Ensure the token is valid and the entity matches.
    return $time < $valid_until && $route == $record->route;
  }

  /**
   * Release tokens that have been created.
   */
  public function release() {
    return $this->connection->query('TRUNCATE quant_token');
  }

}
