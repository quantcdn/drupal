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
   * @return array
   *   Return array of response data.
   *
   * @TODO: Error handlers :D
   * @throws \Drupal\quant_api\Error\InvalidPayload
   * @throws \Drupal\quant_api\Error\InvalidResposne
   */
  public function send(array $data) : array;


  /**
   * Send a file to the API.
   *
   * @param string $file
   *   The path of a file on disk.
   *
   * @param string $url
   *   The relative URL of the file.
   *
   * @param string $rid
   *   The optional revision id.
   *
   * @return array
   *   Return array of response data.
   *
   * @TODO: Error handlers :D
   * @throws \Drupal\quant_api\Error\InvalidPayload
   * @throws \Drupal\quant_api\Error\InvalidResposne
   */
  public function sendFile(string $file, string $url, integer $rid=null) : array;

}
