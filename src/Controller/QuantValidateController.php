<?php

namespace Drupal\quant\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Returns a success response on a Quant route.
 */
class QuantValidateController {

  /**
   * Returns a simple Symfony Http Response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The validation content to return.
   */
  public function index() {
    $response = new Response();
    $response->setContent('quant success');
    return $response;
  }

}
