<?php

namespace Drupal\quant_api\Client;

/**
 * Describe what the Quant client looks like.
 */
interface QuantClientInterface {

  /**
   * Test the connection to the API controller.
   *
   * @return mixed
   *   Contains project machine name if successful
   */
  public function ping();

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
   * @throws \Drupal\quant_api\Exception\InvalidPayload
   * @throws \Drupal\quant_api\Exception\InvalidResposne
   */
  public function send(array $data) : array;


  /**
   * Send a file to the API.
   *
   * @param string $file
   *   The path of a file on disk.
   * @param string $url
   *   The relative URL of the file.
   * @param int $rid
   *   The optional revision id.
   *
   * @return array
   *   Return array of response data.
   *
   * @TODO: Error handlers :D
   * @throws \Drupal\quant_api\Exception\InvalidPayload
   * @throws \Drupal\quant_api\Exception\InvalidResposne
   */
  public function sendFile(string $file, string $url, int $rid = NULL) : array;

}
