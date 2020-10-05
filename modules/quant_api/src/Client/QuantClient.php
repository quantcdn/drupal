<?php

namespace Drupal\quant_api\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Exception\InvalidPayload;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Request;

/**
 *
 */
class QuantClient implements QuantClientInterface {

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The client account.
   *
   * @var string
   */
  protected $username;

  /**
   * The client key.
   *
   * @var string
   */
  protected $token;

  /**
   * The project in Quant.
   *
   * @var string
   */
  protected $project;

  /**
   * The client endpoint.
   *
   * @var string
   */
  protected $endpoint;

  /**
   * {@inheritdoc}
   */
  public function __construct(Client $client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $config = $config_factory->get('quant_api.settings');
    $this->client = $client;
    $this->logger = $logger_factory->get('quant_api');

    $this->username = $config->get('api_account');
    $this->token = $config->get('api_token');
    $this->project = $config->get('api_project');
    $this->endpoint = $config->get('api_endpoint');
  }

  /**
   * {@inheritdoc}
   */
  public function ping() {

    try {
      $response = $this->client->get($this->endpoint . "/ping", [
        'http_errors' => FALSE,
        'headers' => [
          'Quant-Customer' => $this->username,
          'Quant-Project'  => $this->project,
          'Quant-Token'    => $this->token,
        ],
        'exceptions' => FALSE,
      ]);
    }
    catch (RequestException $e) {
      \Drupal::messenger()->addError(t($e->getMessage()));
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $data) : array {
    $response = $this->client->post($this->endpoint, [
      RequestOptions::JSON => $data,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function sendRedirect(array $data) : array {
    $response = $this->client->post($this->endpoint . '/redirect', [
      RequestOptions::JSON => $data,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function sendFile(string $file, string $url, int $rid = NULL) : array {

    // Ensure the file is accessible before attempting to send to the API.
    if (!file_exists($file) || !is_readable($file) || !is_file($file)) {
      throw new InvalidPayload($file);
    }

    // Prepare a stream.
    $resource = fopen($file, 'r');
    $stream = Psr7\stream_for($resource);

    $headers = [
      'Quant-File-Url' => $url,
      'Quant-Customer' => $this->username,
      'Quant-Project' => $this->project,
      'Quant-Token' => $this->token,
    ];

    $request = new Request(
      'POST',
      $this->endpoint,
      $headers,
      new Psr7\MultipartStream([
        [
          'name' => basename($file),
          'filename' => basename($file),
          'contents' => $stream,
        ],
      ])
    );

    $response = $this->client->send($request);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Send an unpublish request to Quant.
   *
   * @param string $url
   *   The url to unpublish.
   *
   * @return array
   *   The API response.
   */
  public function unpublish(string $url) : array {
    $response = $this->client->patch($this->endpoint . '/unpublish', [
      'headers' => [
        'Quant-Url' => $url,
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ]
    ]);

    return json_decode($response->getBody(), TRUE);
  }

}
