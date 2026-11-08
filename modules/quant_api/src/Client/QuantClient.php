<?php

namespace Drupal\quant_api\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant\PublishGuard;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\Exception\InvalidPayload;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
   * Resolves the write token (settings.php, OAuth connection or config).
   *
   * @var \Drupal\quant_api\Credentials|null
   */
  protected $credentials;

  /**
   * {@inheritdoc}
   */
  public function __construct(Client $client, ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory, ?Credentials $credentials = NULL) {
    $this->configFactory = $config_factory;
    $this->client = $client;
    $this->logger = $logger_factory->get('quant_api');
    $this->credentials = $credentials;

    $this->refreshCredentials();
  }

  /**
   * Tells the editor that Quant could not be reached, and logs the cause.
   *
   * The exception text names the request URL, which can carry credentials,
   * so the editor sees a fixed message and the log gets the class and the
   * text without any user information in URLs.
   */
  protected function reportUnreachable(GuzzleException $e) : void {
    $this->logger->error('Quant API request failed (@class): @message', [
      '@class' => get_class($e),
      '@message' => preg_replace('#(https?://)[^/\s@]+@#i', '$1', $e->getMessage()),
    ]);
    \Drupal::messenger()->addError(t('This site could not reach Quant. Check the API endpoint on the Quant API settings page, or try again in a few minutes. After Quant is back, save the changed content again or run a seed.'));
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
    // An OAuth connection keeps its token outside config.
    $this->token = $this->credentials ? $this->credentials->getToken($this->config) : $this->config->get('api_token');
    $this->project = $this->config->get('api_project');
    $this->endpoint = ($this->credentials ? $this->credentials->getEndpoint($this->config) : $this->config->get('api_endpoint')) . '/v1';
    $this->tlsDisabled = $this->config->get('api_tls_disabled');
  }

  /**
   * Refuses any call when no project token is set: no request leaves Drupal.
   *
   * An unconnected site used to send requests with empty credentials (for
   * example an unpublish when a search page was deleted). Fail closed and say
   * why, once per process.
   *
   * @param string $operation
   *   What is being attempted, for the log.
   *
   * @return bool
   *   TRUE when the caller must not proceed.
   */
  protected function refusesWithoutToken(string $operation) : bool {
    if (!empty($this->token)) {
      return FALSE;
    }
    static $logged = FALSE;
    if (!$logged) {
      $logged = TRUE;
      $copy = $this->credentials && $this->credentials->usesOauth($this->config) ? $this->credentials->copyDifferences() : [];
      if ($copy !== []) {
        $this->logger->error('Quant: this site looks like a copy of the connected site (different: @components), so nothing is published (@operation). Log in to Quant again, or confirm it is the same site on the Quant API settings page.', [
          '@components' => implode(', ', $copy),
          '@operation' => $operation,
        ]);
        return TRUE;
      }
      $this->logger->warning('Quant API: no project token is set, so the module did not call the API (@operation). Connect the site on the Quant API settings page.', [
        '@operation' => $operation,
      ]);
    }
    return TRUE;
  }

  /**
   * Refuses a write the current context cannot be trusted to address.
   *
   * The event subscriber stops most work earlier, and more cheaply. This
   * catches the writes that never dispatch an event — search records, facets
   * and index clearing all call this client directly — so a method added
   * later is covered without anyone remembering to guard it.
   *
   * @param string $operation
   *   What is being attempted, for the log.
   *
   * @return bool
   *   TRUE when the caller must not proceed.
   */
  protected function refusesWrite(string $operation) : bool {
    if ($this->refusesWithoutToken($operation)) {
      return TRUE;
    }
    if (!PublishGuard::refuses($host)) {
      return FALSE;
    }

    PublishGuard::logRefusal($operation, $host);

    return TRUE;
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
    if ($this->refusesWithoutToken('to check the connection')) {
      return FALSE;
    }

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
    catch (GuzzleException $e) {
      $this->reportUnreachable($e);
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
    if ($this->refusesWithoutToken('to read the project')) {
      return FALSE;
    }

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
    catch (GuzzleException $e) {
      $this->reportUnreachable($e);
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
    if ($this->refusesWithoutToken('to read the search index')) {
      return FALSE;
    }

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
    catch (GuzzleException $e) {
      $this->reportUnreachable($e);
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
  public function sendFormConfig(string $url, bool $enabled, array $config = []) : bool {
    $this->refreshCredentials();

    if ($this->refusesWrite('to set the form config of ' . $url)) {
      return FALSE;
    }

    $data = ['url' => $url, 'form_enabled' => $enabled];
    if ($enabled || $config !== []) {
      $data['form_config'] = $config;
    }
    try {
      $response = $this->client->post($this->endpoint . '/form', [
        RequestOptions::JSON => $data,
        'headers' => [
          'Quant-Customer' => $this->username,
          'Quant-Project'  => $this->project,
          'Quant-Token'    => $this->token,
        ],
        'http_errors' => FALSE,
        'verify' => $this->tlsDisabled ? FALSE : TRUE,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Quant Forms: the form config of @url was not sent (@class).', [
        '@url' => $url,
        '@class' => get_class($e),
      ]);
      return FALSE;
    }
    if ($response->getStatusCode() !== 200) {
      // The API refuses a page that is not published yet.
      $this->logger->warning('Quant Forms: the form config of @url was refused with status @status.', [
        '@url' => $url,
        '@status' => $response->getStatusCode(),
      ]);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $data) : array {
    $this->refreshCredentials();

    if ($this->refusesWrite('to publish ' . ($data['url'] ?? 'content'))) {
      return [];
    }

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

    if ($this->refusesWrite('to publish a redirect for ' . ($data['url'] ?? 'a url'))) {
      return [];
    }

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

    if ($this->refusesWrite('to upload ' . $url)) {
      return [];
    }

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

    if ($this->refusesWrite('to unpublish ' . $url)) {
      return [];
    }

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
    if ($this->refusesWithoutToken('to read page metadata')) {
      return [];
    }
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

    if ($this->refusesWrite('to write ' . count($records) . ' search records')) {
      return [];
    }

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

    if ($this->refusesWrite('to clear the search index')) {
      return [];
    }

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

    if ($this->refusesWrite('to write search facets')) {
      return [];
    }

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
