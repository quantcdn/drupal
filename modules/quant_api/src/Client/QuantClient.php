<?php

namespace Drupal\quant_api\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Exception\InvalidPayload;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;

/**
 * Quant API client.
 */
class QuantClient implements QuantClientInterface {

  /**
   * The configuration object for Quant API.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The Guzzle client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

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
   * TLS disable boolean.
   *
   * @var bool
   */
  protected $tlsDisabled = FALSE;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(Client $client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->client = $client;
    $this->logger = $logger_factory->get('quant_api');

    $this->refreshCredentials();
  }

  /**
   * Re-reads the API credentials from configuration.
   *
   * This service is a singleton for the lifetime of the process. On a
   * multi-domain site the target project is not known until the active
   * domain is negotiated, and that happens after the container is built.
   * Values captured once in the constructor pin every subsequent request to
   * whichever project the base configuration names, so they are re-read
   * immediately before each API call.
   */
  protected function refreshCredentials() : void {
    // Re-fetch from the factory rather than reuse a cached config object: a
    // domain switch resets the factory and produces a new object.
    $this->config = $this->configFactory->get('quant_api.settings');

    $this->username = $this->config->get('api_account');
    $this->token = $this->config->get('api_token');
    $this->project = $this->config->get('api_project');
    $this->endpoint = $this->config->get('api_endpoint') . '/v1';
    $this->tlsDisabled = $this->config->get('api_tls_disabled');
  }

  /**
   * Returns the Quant project this client currently targets.
   *
   * @return string|null
   *   The project machine name, or NULL when none is configured.
   */
  public function getProject() : ?string {
    $this->refreshCredentials();
    return $this->project ?: NULL;
  }

  /**
   * Get API overrides.
   */
  public function getOverrides() {
    $this->refreshCredentials();
    // Note this has to be processed in this class instead of in the
    // SettingsForm because the overrides aren't available in the form.
    $overrides = [];
    $keys = [
      'api_endpoint',
      'api_account',
      'api_project',
      'api_token',
      'api_tls_disabled',
    ];
    foreach ($keys as $key) {
      $original = $this->config->getOriginal($key, FALSE);
      $active = $this->config->get($key);
      if ($original != $active) {
        $overrides[$key] = $active;
      }
    }

    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function ping() {
    $this->refreshCredentials();

    try {
      // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
      $response = $this->client->get($this->endpoint . "/ping", [
        'http_errors' => FALSE,
        'headers' => [
          'Quant-Customer' => $this->username,
          'Quant-Project'  => $this->project,
          'Quant-Token'    => $this->token,
        ],
        'exceptions' => FALSE,
        'verify' => $this->tlsDisabled ? FALSE : TRUE,
      ]);
    }
    catch (RequestException $e) {
      \Drupal::messenger()->addError($e->getMessage());
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      return TRUE;
    }

    if ($response->getStatusCode() == 402) {
      // Emit a subscription invalid warning.
      \Drupal::messenger()->addError(t('Your Quant subscription is invalid. Please check the dashboard.'));
    }

    if ($response->getStatusCode() == 410) {
      // Emit a deleted project warning.
      \Drupal::messenger()->addError(t('Project is deleted. Please check the dashboard for restoration options.'));
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function project() {
    $this->refreshCredentials();

    try {
      // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
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
      \Drupal::messenger()->addError($e->getMessage());
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      return json_decode($response->getBody());
    }

    if ($response->getStatusCode() == 402) {
      // Emit a subscription invalid warning.
      \Drupal::messenger()->addError(t('Your Quant subscription is invalid. Please check the dashboard.'));
    }

    if ($response->getStatusCode() == 410) {
      // Emit a deleted project warning.
      \Drupal::messenger()->addError(t('Project is deleted. Please check the dashboard for restoration options.'));
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function search() {
    $this->refreshCredentials();

    try {
      // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
      $response = $this->client->get($this->endpoint . "/search", [
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
      \Drupal::messenger()->addError($e->getMessage());
      return FALSE;
    }

    if ($response->getStatusCode() == 200) {
      return json_decode($response->getBody());
    }

    if ($response->getStatusCode() == 402) {
      // Emit a subscription invalid warning.
      \Drupal::messenger()->addError(t('Your Quant subscription is invalid. Please check the dashboard.'));
    }

    if ($response->getStatusCode() == 410) {
      // Emit a deleted project warning.
      \Drupal::messenger()->addError(t('Project is deleted. Please check the dashboard for restoration options.'));
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $data) : array {
    $this->refreshCredentials();
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->post($this->endpoint, [
      RequestOptions::JSON => $data,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function sendRedirect(array $data) : array {
    $this->refreshCredentials();
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->post($this->endpoint . '/redirect', [
      RequestOptions::JSON => $data,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function sendFile(string $file, string $url, ?int $rid = NULL) : array {
    $this->refreshCredentials();

    // Ensure the file is accessible before attempting to send to the API.
    if (!file_exists($file) || !is_readable($file) || !is_file($file)) {
      throw new InvalidPayload($file);
    }

    // Prepare a stream.
    $resource = fopen($file, 'r');
    $stream = Utils::streamFor($resource);

    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
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
      new MultipartStream([
        [
          'name' => basename($file),
          'filename' => basename($file),
          'contents' => $stream,
        ],
      ])
    );

    $response = $this->client->send($request, [
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

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
    $this->refreshCredentials();
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->patch($this->endpoint . '/unpublish', [
      'headers' => [
        'Quant-Url' => $url,
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Gets global metadata for the given URLs.
   *
   * @param array $urls
   *   The urls to get metadata for.
   *
   * @return array
   *   The API response.
   */
  public function getUrlMeta(array $urls) : array {
    $this->refreshCredentials();
    // Format array if it's not already.
    if (!array_key_exists('Quant-Url', $urls)) {
      $urls = [
        'Quant-Url' => $urls,
      ];
    }
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->post($this->endpoint . '/url-meta', [
      RequestOptions::JSON => $urls,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function sendSearchRecords(array $records) : array {
    $this->refreshCredentials();
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->post($this->endpoint . '/search', [
      RequestOptions::JSON => $records,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function clearSearchIndex() : array {
    $this->refreshCredentials();
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->delete($this->endpoint . '/search/all', [
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function addFacets(array $facets) : array {
    $this->refreshCredentials();
    // @todo Switch from 'Quant-Customer' to 'Quant-Organization'.
    $response = $this->client->post($this->endpoint . '/search/facet', [
      RequestOptions::JSON => $facets,
      'headers' => [
        'Quant-Customer' => $this->username,
        'Quant-Project'  => $this->project,
        'Quant-Token'    => $this->token,
      ],
      'verify' => $this->tlsDisabled ? FALSE : TRUE,
    ]);

    return json_decode($response->getBody(), TRUE);
  }

}
