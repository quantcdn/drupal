<?php

namespace Drupal\quant_api\Client;

/**
 * Describe what the Quant client looks like.
 */
interface QuantClientInterface {

  /**
   * Test the connection to the API controller.
   *
   * @return bool
   *   If API credentials can
   */
  public function ping() : bool;

  /**
   * Send a payload to the API.
   *
   * @param array $data
   *   The data array to send.
   *
   * @return bool
   *   If the request was successful
   *
   * @TODO: Error handlers :D
   * @throws \Drupal\quant_api\Error\InvalidPayload
   * @throws \Drupal\quant_api\Error\InvalidResposne
   */
  public function send(array $data) : bool;

}
