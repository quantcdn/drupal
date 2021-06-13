<?php

namespace Drupal\quant\Exception;

/**
 * The token has expired.
 */
class InvalidTokenException extends \Exception {

  /**
   * Token from the database.
   *
   * @var string
   */
  protected $token;

  /**
   * The request time.
   *
   * @var int
   */
  protected $time;

  /**
   * {@inheritdoc}
   */
  public function __construct(string $token, int $time = 0, string $message = "Invalid request token", int $code = 0, \Throwable $previous = NULL) {
    $this->token = $token;
    $this->time = $time;

    parent::__construct($message, $code, $previous);
  }

  /**
   * Getter for the token.
   */
  public function getToken() {
    return $this->token;
  }

  /**
   * Getter for the time.
   */
  public function getTime() {
    return $this->time;
  }

}
