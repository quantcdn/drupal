<?php

namespace Drupal\quant\Exception;

/**
 * The token has expired.
 */
class ExpiredTokenException extends \Exception {

  /**
   * Request token.
   *
   * @var string
   */
  protected $token;

  /**
   * Request time.
   *
   * @var int
   */
  protected $time;

  /**
   * The matched record.
   *
   * @var object
   */
  protected $record;

  /**
   * {@inheritdoc}
   */
  public function __construct(string $token, int $time = 0, $record = [], string $message = "The token has expired", int $code = 0, \Throwable $previous = NULL) {
    $this->token = $token;
    $this->time = $time;
    $this->record = $record;

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

  /**
   * Getter for the database record.
   */
  public function getRecord() {
    return $this->record;
  }

}
