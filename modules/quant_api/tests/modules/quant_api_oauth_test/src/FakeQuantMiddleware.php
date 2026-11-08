<?php

namespace Drupal\quant_api_oauth_test;

use Drupal\Core\State\StateInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Answers the module's dashboard and content API calls with canned data.
 *
 * The fake dashboard lives under /quant-test-dashboard on the test site's own
 * host. It checks PKCE and the project limit like the real one, so the tests
 * fail when the module skips a step.
 */
class FakeQuantMiddleware {

  public const WRITE_TOKEN_PREFIX = 'fake-write-token-';

  public function __construct(protected StateInterface $state) {}

  /**
   * The Guzzle middleware.
   */
  public function __invoke() {
    return function (callable $handler) {
      return function (RequestInterface $request, array $options) use ($handler) {
        $response = $this->answer($request);
        return $response ? Create::promiseFor($response) : $handler($request, $options);
      };
    };
  }

  /**
   * The canned answer, or NULL to let the request through.
   */
  protected function answer(RequestInterface $request): ?Response {
    $path = $request->getUri()->getPath();
    if (in_array($request->getUri()->getHost(), ['api.quantcdn.io', 'api.quantgov.cloud'], TRUE) && $path === '/v1/ping') {
      return $this->ping($request);
    }
    $at = strpos($path, '/quant-test-dashboard/');
    if ($at === FALSE) {
      return NULL;
    }
    $sub = substr($path, $at + strlen('/quant-test-dashboard'));
    if ($sub === '/oauth/authorize') {
      // The browser's approval page: served by the test site itself.
      return NULL;
    }
    $this->record($request);
    return $this->dashboard($request, $sub);
  }

  /**
   * The dashboard API.
   */
  protected function dashboard(RequestInterface $request, string $path): Response {
    $method = $request->getMethod();
    return match (TRUE) {
      $method === 'POST' && $path === '/oauth/token' => $this->token($request),
      $method === 'GET' && $path === '/api/v2/organizations/test-org/projects' => $this->bearer($request, $this->limited() ? self::json(403, ['code' => 'PROJECT_LEVEL_ONLY']) : self::json(200, [
        ['machine_name' => 'site-a', 'name' => 'Site A'],
        ['machine_name' => 'site-b', 'name' => 'Site B'],
      ])),
      $method === 'GET' && $path === '/api/oauth/user' => $this->bearer($request, self::json(200, [
        'id' => 7,
        'name' => 'Test Editor',
        'email' => 'editor@example.com',
        'organizations' => [['id' => 3, 'machine_name' => 'test-org', 'name' => 'Test Org', 'roles' => []]],
      ])),
      $method === 'POST' && $path === '/api/oauth/revoke' => $this->bearer($request, self::json(200, ['revoked' => TRUE])),
      $method === 'POST' && $path === '/api/oauth/token/project' => $this->bearer($request, $this->limit($request)),
      $method === 'GET' && preg_match('#^/api/v2/organizations/test-org/projects/([a-z-]+)$#', $path, $m) === 1 => $this->bearer($request, $this->project($m[1])),
      default => self::json(404, ['message' => 'Not found']),
    };
  }

  /**
   * The token endpoint: checks the PKCE verifier against the challenge.
   */
  protected function token(RequestInterface $request): Response {
    parse_str((string) $request->getBody(), $form);
    $challenge = (string) ($this->state->get('quant_api_oauth_test.authorize')['code_challenge'] ?? '');
    $expected = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), TRUE)), '+/', '-_'), '=');
    if (($form['code'] ?? '') !== 'test-code' || $challenge === '' || !hash_equals($challenge, $expected) || ($form['client_id'] ?? '') !== 'drupal-quant') {
      return self::json(400, ['error' => 'invalid_grant']);
    }
    $this->state->delete('quant_api_oauth_test.limited');
    return self::json(200, [
      'access_token' => 'qca_fake_access',
      'refresh_token' => 'qcr_fake_refresh',
      'token_type' => 'Bearer',
      'expires_in' => 7776000,
      'scope' => 'projects:read',
      // The real dashboard sends only id, email and name here. The
      // organisations come from GET /api/oauth/user.
      'user' => [
        'id' => 7,
        'name' => 'Test Editor',
        'email' => 'editor@example.com',
      ],
    ]);
  }

  /**
   * The project limit: narrows once, refuses to widen.
   */
  protected function limit(RequestInterface $request): Response {
    $body = json_decode((string) $request->getBody(), TRUE) ?: [];
    $limited = $this->limited();
    if ($limited !== NULL && $limited !== ($body['project'] ?? NULL)) {
      return self::json(409, ['code' => 'TOKEN_ALREADY_LIMITED']);
    }
    $this->state->set('quant_api_oauth_test.limited', (string) ($body['project'] ?? ''));
    return self::json(200, ['organization' => $body['organization'] ?? '', 'project' => $body['project'] ?? '']);
  }

  /**
   * One project with its write token, for the limited project only.
   */
  protected function project(string $project): Response {
    if ($this->limited() !== $project) {
      return self::json(403, ['code' => 'PROJECT_LEVEL_ONLY']);
    }
    return self::json(200, ['machine_name' => $project, 'write_token' => self::WRITE_TOKEN_PREFIX . $project]);
  }

  /**
   * The content API ping: 200 for the write token of the limited project.
   */
  protected function ping(RequestInterface $request): Response {
    $ok = $request->getHeaderLine('Quant-Token') === self::WRITE_TOKEN_PREFIX . $request->getHeaderLine('Quant-Project');
    return self::json($ok ? 200 : 401, $ok ? ['project' => $request->getHeaderLine('Quant-Project')] : ['error' => TRUE]);
  }

  /**
   * Requires the bearer of the login.
   */
  protected function bearer(RequestInterface $request, Response $response): Response {
    return $request->getHeaderLine('Authorization') === 'Bearer qca_fake_access' ? $response : self::json(401, ['message' => 'Invalid token']);
  }

  /**
   * The project the login is limited to, if any.
   */
  protected function limited(): ?string {
    return $this->state->get('quant_api_oauth_test.limited');
  }

  /**
   * Records a request: method and path only.
   */
  protected function record(RequestInterface $request): void {
    $log = $this->state->get('quant_api_oauth_test.requests', []);
    $log[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
    $this->state->set('quant_api_oauth_test.requests', $log);
  }

  /**
   * A JSON response.
   */
  protected static function json(int $status, array $body): Response {
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
  }

}
