<?php

namespace Drupal\quant;

use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Simple interface to manage short-lived access tokens.
 *
 * @ingroup quant
 */
class TokenManager {

  /**
   * Token timeout.
   */
  const ELAPSED = '+120 days';

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
   * Construct a TokenManager instance.
   *
   * @param Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(Connection $connection, RequestStack $request) {
    $this->connection = $connection;
    $this->request = $request;
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
   * @return string
   *   The token.
   */
  public function create($entity_id) {
    // @todo table has DEFAULT now() but this was causing
    // issues with request time mismatches so for now we just
    // insert the request time for create.
    $time = $this->request->getCurrentRequest()->server->get('REQUEST_TIME');
    $token = $this->generate();
    $query = $this->connection->insert('quant_token')
      ->fields([
        'nid' => $entity_id,
        'token' => $token,
        'created' => date('Y-m-d H:i:s', $time),
      ]);

    try {
      $query->execute();
    }
    catch (\Exception $error) {
      var_dump($error->getMessage());
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
   * @param int $entity_id
   *   The entity to validate.
   * @param bool $strict
   *   If we should validate the entity_id in the check.
   *
   * @return bool
   *   If the token is valid.
   */
  public function validate($entity_id = NULL, $strict = TRUE) {
    $token = $this->request->getCurrentRequest()->get('quant_token');
    $time = $this->request->getCurrentRequest()->server->get('REQUEST_TIME');

    if (empty($token)) {
      return FALSE;
    }

    $query = $this->connection->select('quant_token', 'qt')
      ->condition('qt.token', $token)
      ->fields('qt', ['nid', 'created'])
      ->range(0, 1);

    try {
      $record = $query->execute()->fetchObject();
    }
    catch (\Exception $error) {
      return FALSE;
    }

    if (!$strict) {
      return $time < $valid_until;
    }

    // Ensure the token is valid and the entity matches.
    $valid_until = strtotime(self::ELAPSED, strtotime($record->created));
    return $time < $valid_until && $entity_id == $record->nid;
  }

  /**
   * Release tokens that have been created.
   */
  public function release() {
    return $this->connection->query('TRUNCATE quant_token');
  }

}
