<?php

namespace Drupal\quant\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

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
    $response = new JsonResponse(['status' => 'okay']);
    $response->setStatusCode(JsonResponse::HTTP_OK);

    $request = \Drupal::request();

    $request->headers->set('quant-token', \Drupal::service('quant.token_manager')->create("/quant/validate"));

    try {
      \Drupal::service('quant.token_manager')->validate("/quant/validate", FALSE);
    }
    catch (\Exception $e) {
      $response->setData([
        'status' => 'error',
        'reason' => 'Invalid token signature, please regenerate token.',
      ]);
    }

    $client = \Drupal::service('quant_api.client');
    if (!$client->ping()) {
      $response->setData([
        'status' => 'error',
        'reason' => 'Cannot connect to the QuantAPI.',
      ]);
    }

    return $response;
  }

}
