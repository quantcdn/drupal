<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The dashboard API calls of "Log in to Quant", made with the OAuth token.
 *
 * The module uses the OAuth token for four calls only: the user, the project
 * list, the project limit and the project write token. Content, forms and
 * search use the write token on the content API.
 */
class PortalClient {

  protected const TIMEOUT = 30;

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
   * Whether a value is a safe organisation or project machine name.
   */
  public static function isMachineName(string $value): bool {
    return preg_match(self::MACHINE_NAME, $value) === 1;
  }

  /**
   * Sends one call and returns the decoded data, or NULL on any failure.
   */
  protected function send(string $method, string $path, string $access_token, ?array $json = NULL): ?array {
    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $access_token,
        'Accept' => 'application/json',
      ],
      'timeout' => self::TIMEOUT,
      'http_errors' => FALSE,
    ];
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
      return NULL;
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || !is_array($data)) {
      $this->logger->warning('The Quant dashboard call @method @path returned status @status.', [
        '@method' => $method,
        '@path' => strtok($path, '?'),
        '@status' => $response->getStatusCode(),
      ]);
      return NULL;
    }
    return isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
  }

}
