<?php

namespace Drupal\quant\Exception;

/**
 * The token has expired.
 */
class StrictTokenException extends \Exception {

  /**
   * Request token.
   *
   * @var string
   */
  protected $token;

  /**
   * Request time.
   *
   * @var string
   */
  protected $tokenRoute;

  /**
   * The matched record.
   *
   * @var string
   */
  protected $expectedRoute;

  /**
   * {@inheritdoc}
   */
  public function __construct(string $token, $token_route = NULL, $expected_route = NULL, string $message = "The token routes do not match", int $code = 0, \Throwable $previous = NULL) {
    $this->token = $token;
    $this->tokenRoute = $token_route;
    $this->expectedRoute = $expected_route;

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
  public function getTokenRoute() {
    return $this->tokenRoute;
  }

  /**
   * Getter for the database record.
   */
  public function getExpectedRoute() {
    return $this->expectedRoute;
  }

}
