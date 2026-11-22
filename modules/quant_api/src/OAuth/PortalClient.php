<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The dashboard API calls of "Log in to Quant", made with the OAuth token.
 *
 * The module uses the OAuth token for five calls only: the user, the project
 * list, the project limit, the project write token and the alert channels of
 * the project. Content, forms and search use the write token on the content
 * API.
 */
class PortalClient {

  protected const TIMEOUT = 30;

  /**
   * The alert channels are read while a settings page loads: a short wait.
   */
  protected const LIST_HTTP = ['connect_timeout' => 3, 'timeout' => 5];

  /**
   * The HTTP options of the other calls.
   */
  protected const HTTP = ['timeout' => self::TIMEOUT];

  /**
   * Only machine names reach a URL path.
   */
  protected const MACHINE_NAME = '/^[a-z0-9][a-z0-9_-]{0,127}$/';

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected ClientInterface $httpClient,
    protected AuthService $auth,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('quant_api');
  }

  /**
   * The user and the organisations the login granted.
   *
   * @return array|null
   *   The user (name, email, organizations), or NULL on failure.
   */
  public function getUser(string $access_token): ?array {
    $data = $this->send('GET', '/api/oauth/user', $access_token);
    return is_array($data) ? ($data['user'] ?? $data) : NULL;
  }

  /**
   * The projects of one organisation.
   *
   * @return array|null
   *   A list of ['machine_name' => ..., 'name' => ...], or NULL on failure.
   */
  public function listProjects(string $access_token, string $organization): ?array {
    if (!self::isMachineName($organization)) {
      return NULL;
    }
    $data = $this->send('GET', '/api/v2/organizations/' . $organization . '/projects', $access_token);
    if (!is_array($data)) {
      return NULL;
    }
    $projects = [];
    foreach ($data as $project) {
      if (is_array($project) && self::isMachineName((string) ($project['machine_name'] ?? ''))) {
        $projects[] = [
          'machine_name' => (string) $project['machine_name'],
          'name' => (string) ($project['name'] ?? $project['machine_name']),
        ];
      }
    }
    return $projects;
  }

  /**
   * Limits the token to one project. The limit cannot be widened later.
   */
  public function limitToProject(string $access_token, string $organization, string $project): bool {
    if (!self::isMachineName($organization) || !self::isMachineName($project)) {
      return FALSE;
    }
    $data = $this->send('POST', '/api/oauth/token/project', $access_token, [
      'organization' => $organization,
      'project' => $project,
    ]);
    return is_array($data) && ($data['project'] ?? NULL) === $project;
  }

  /**
   * The write token of one project, for the content API.
   */
  public function fetchWriteToken(string $access_token, string $organization, string $project): ?string {
    if (!self::isMachineName($organization) || !self::isMachineName($project)) {
      return NULL;
    }
    $data = $this->send('GET', '/api/v2/organizations/' . $organization . '/projects/' . $project . '?with_token=1', $access_token);
    $token = is_array($data) ? (string) ($data['write_token'] ?? '') : '';
    return $token !== '' ? $token : NULL;
  }

  /**
   * The alert channels that the forms of one project can use.
   *
   * @return array
   *   - status: the HTTP status, or 0 when no answer came.
   *   - channels: the rows of the answer (unchecked), or an empty list.
   */
  public function listAlertChannels(string $access_token, string $organization, string $project): array {
    if (!self::isMachineName($organization) || !self::isMachineName($project)) {
      return ['status' => 0, 'channels' => []];
    }
    $path = '/api/v3/organizations/' . $organization . '/projects/' . $project . '/alert-channels';
    // A refused login is an expected state here (the editor reconnects).
    $result = $this->request('GET', $path, $access_token, NULL, self::LIST_HTTP, [401, 403]);
    $channels = $result['data']['channels'] ?? [];
    return [
      'status' => $result['status'],
      'channels' => $result['status'] === 200 && is_array($channels) ? array_values($channels) : [],
    ];
  }

  /**
   * Whether a value is a safe organisation or project machine name.
   */
  public static function isMachineName(string $value): bool {
    return preg_match(self::MACHINE_NAME, $value) === 1;
  }

  /**
   * Sends one call and returns the decoded data, or NULL on any failure.
   */
  protected function send(string $method, string $path, string $access_token, ?array $json = NULL): ?array {
    $result = $this->request($method, $path, $access_token, $json, self::HTTP);
    return $result['status'] === 200 ? $result['data'] : NULL;
  }

  /**
   * Sends one call and returns its status and decoded data.
   *
   * @return array
   *   - status: the HTTP status; 0 when no answer came, or when a 200 answer
   *     is not JSON.
   *   - data: the decoded data ('data' unwrapped), or NULL.
   */
  protected function request(string $method, string $path, string $access_token, ?array $json, array $http, array $quiet = []): array {
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
        'Accept' => 'application/json',
      ],
      'http_errors' => FALSE,
    ] + $http;
    if ($json !== NULL) {
      $options['json'] = $json;
    }
    try {
      $response = $this->httpClient->request($method, $this->auth->getInternalDashboardUrl() . $path, $options);
    }
    catch (\Exception $e) {
      $this->logger->error('The Quant dashboard call @method @path failed (@class).', [
        '@method' => $method,
        '@path' => strtok($path, '?'),
        '@class' => get_class($e),
      ]);
      return ['status' => 0, 'data' => NULL];
    }
    $status = $response->getStatusCode();
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($status !== 200 || !is_array($data)) {
      $this->logger->log(in_array($status, $quiet, TRUE) ? LogLevel::DEBUG : LogLevel::WARNING, 'The Quant dashboard call @method @path returned status @status.', [
        '@method' => $method,
        '@path' => strtok($path, '?'),
        '@status' => $status,
      ]);
      return ['status' => $status === 200 ? 0 : $status, 'data' => NULL];
    }
    return [
      'status' => 200,
      'data' => isset($data['data']) && is_array($data['data']) ? $data['data'] : $data,
    ];
  }

}
