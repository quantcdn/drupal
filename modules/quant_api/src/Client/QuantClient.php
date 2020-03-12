<?php

namespace Drupal\quant_api\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;


class QuantClient implements QuantClientInterface {

  /**
   * Build the QuantClient instance.
   */
  public function __construct(Client $client, ConfigFactoryInterface $config_factory) {
    $config = $config_factory->get('quant_api.settings');
    $this->client = $client;
    // @TODO: Grab API connection details from the config.
  }

  /**
   * {@inheritdoc}
   */
  public function ping() : bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $data) : array {

    // @todo: Exception handling, error reporting.
    $response = $this->client->post('http://api:80/', [
      RequestOptions::JSON => $data,
      'http_errors' => FALSE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }


  /**
   * {@inheritdoc}
   */
  public function sendFile(string $file, string $url, integer $rid=null) : array {

    // @todo: Exception handling, error reporting.
    $response = $this->client->post('http://api:80/', [
      'headers' => [
        'Quant-File-Url' => $url,
      ],
      'multipart' => [
        [
          'name' => 'filename',
          'filename' => basename($file),
          'contents' => fopen($file, 'r')
        ]
      ],
      'http_errors' => FALSE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }


}
