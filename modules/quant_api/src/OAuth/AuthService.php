<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * The OAuth protocol of "Log in to Quant": PKCE, code exchange and refresh.
 *
 * The refresh rules follow the portal. The portal rotates the refresh token on
 * every use. A replay within 30 s gets "invalid_grant ... just rotated". A
 * replay from 30 s to 300 s of a rotation whose new access token was never
 * used rotates once more (a lost answer is recovered). Any later replay
 * revokes the chain. So only the holder of the refresh lock sends a refresh
 * token, it saves the rotated pair before it releases the lock, and after a
 * request with no answer it sends the same token again only inside that
 * recovery window. A failed refresh never deletes the stored tokens.
 *
 * Publishing uses the project write token, which does not expire. A failed
 * refresh therefore stops only the project list and the token re-sync.
 */
class AuthService {

  public const RECONNECT_OTHER_SITE = 'other_site';

  public const RECONNECT_REFUSED = 'refused';

  public const RECONNECT_EXPIRED = 'expired';

  protected const REFRESH_LOCK_ID = 'quant_api.oauth_refresh';

  protected const REFRESH_LOCK_TIMEOUT = 90;

  protected const REFRESH_LOCK_WAIT = 30;

  protected const REFRESH_HTTP_TIMEOUT = 60;

  /**
   * Back-off after an unknown outcome, for requests and for cron.
   */
  protected const REQUEST_BACKOFF = 60;

  protected const CRON_BACKOFF = 600;

  /**
   * The portal's recovery window for a lost rotation, and the retries in it.
   */
  protected const PENDING_MIN_AGE = 45;

  protected const PENDING_WINDOW = 240;

  protected const PENDING_MAX_RETRIES = 2;

  /**
   * A request refreshes 60 s before expiry; cron 30 days before.
   */
  protected const EXPIRY_MARGIN = 60;

  protected const CRON_MARGIN = 2592000;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The platform of a login in progress, before it is saved.
   */
  protected ?string $platformOverride = NULL;

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected OAuthTokenStore $tokenStore,
    protected SiteFingerprint $fingerprint,
    protected LockBackendInterface $lock,
    protected Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('quant_api');
  }

  /**
   * The platform the site is configured for.
   */
  public function getPlatform(): string {
    $platform = $this->platformOverride ?? (string) $this->configFactory->get('quant_api.settings')->get('platform');
    return Platform::isKnown($platform) ? $platform : Platform::QUANTCDN;
  }

  /**
   * Uses a platform for this request only: a login in progress.
   *
   * The site's platform and endpoint change only when the login completes,
   * so a cancelled login never re-points a working connection.
   */
  public function usePlatform(?string $platform): void {
    $this->platformOverride = Platform::isKnown($platform) ? $platform : NULL;
  }

  /**
   * The dashboard (OAuth server) of the configured platform.
   */
  public function getDashboardUrl(): string {
    return Platform::dashboardUrl($this->getPlatform());
  }

  /**
   * The dashboard for server-to-server calls.
   */
  public function getInternalDashboardUrl(): string {
    return Platform::internalDashboardUrl($this->getPlatform());
  }

  /**
   * A new PKCE code verifier: 32 random bytes, base64url.
   */
  public function generatePkceVerifier(): string {
    return self::base64Url(random_bytes(32));
  }

  /**
   * The S256 code challenge of a verifier.
   */
  public function computePkceChallenge(string $verifier): string {
    return self::base64Url(hash('sha256', $verifier, TRUE));
  }

  /**
   * The dashboard URL that starts the login.
   */
  public function getAuthorizationUrl(string $state, string $redirect_uri, string $code_challenge): string {
    return $this->getDashboardUrl() . '/oauth/authorize?' . http_build_query([
      'client_id' => Platform::CLIENT_ID,
      'response_type' => 'code',
      'redirect_uri' => $redirect_uri,
      'state' => $state,
      'scope' => 'full_access',
      'code_challenge' => $code_challenge,
      'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
  }

  /**
   * Exchanges an authorization code for tokens.
   *
   * @return array|null
   *   The token response, or NULL on any failure (logged without values).
   */
  public function exchangeCode(string $code, string $redirect_uri, string $code_verifier): ?array {
    try {
      $response = $this->httpClient->request('POST', $this->getInternalDashboardUrl() . '/oauth/token', [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'client_id' => Platform::CLIENT_ID,
          'code' => $code,
          'redirect_uri' => $redirect_uri,
          'code_verifier' => $code_verifier,
        ],
        'headers' => ['Accept' => 'application/json'],
        'timeout' => self::REFRESH_HTTP_TIMEOUT,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('The Quant login could not exchange the code (@class).', ['@class' => get_class($e)]);
      return NULL;
    }
    $data = json_decode((string) $response->getBody(), TRUE);
    if ($response->getStatusCode() !== 200 || empty($data['access_token'])) {
      $this->logger->error('The Quant dashboard refused the login code with status @status (@error).', [
        '@status' => $response->getStatusCode(),
        '@error' => is_array($data) ? (string) ($data['error'] ?? 'none') : 'none',
      ]);
      return NULL;
    }
    return $data;
  }

  /**
   * Makes a login limited to one project the site's connection.
   *
   * @param array $tokens
   *   The token response of the login.
   * @param string $write_token
   *   The project write token.
   * @param array $user
   *   The user name and email, for display only.
   */
  public function saveConnection(array $tokens, string $write_token, array $user): void {
    $this->tokenStore->clear();
    $this->tokenStore->saveTokens($tokens);
    $this->tokenStore->setMany([
      OAuthTokenStore::WRITE_TOKEN => $write_token,
      OAuthTokenStore::USER => array_intersect_key($user, array_flip(['name', 'email'])),
      OAuthTokenStore::FINGERPRINT => $this->fingerprint->compute(),
    ]);
  }

  /**
   * Ends the login in the dashboard, then deletes the local connection.
   *
   * The dashboard call is best effort: an older dashboard without the revoke
   * endpoint, or one that cannot be reached, leaves the token to expire on
   * its own. The local connection is deleted in every case.
   */
  public function disconnect(): void {
    $access = $this->tokenStore->get(OAuthTokenStore::ACCESS_TOKEN);
    if (is_string($access) && $access !== '') {
      $this->revokeAtDashboard($access);
    }
    $this->tokenStore->clear();
  }

  /**
   * Asks the dashboard to delete this site's token (POST /api/oauth/revoke).
   */
  protected function revokeAtDashboard(string $access_token): void {
    try {
      $response = $this->httpClient->request('POST', $this->getInternalDashboardUrl() . '/api/oauth/revoke', [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Accept' => 'application/json',
        ],
        'timeout' => 10,
        'http_errors' => FALSE,
      ]);
      $status = $response->getStatusCode();
    }
    catch (\Exception $e) {
      $status = get_class($e);
    }
    if ($status !== 200) {
      $this->logger->notice('The Quant dashboard did not end the login (@status). The token stops working when it expires.', ['@status' => $status]);
    }
  }

  /**
   * The access token, refreshed when it is about to expire.
   *
   * @return string|null
   *   The access token, or NULL when no usable token can be obtained.
   */
  public function getValidAccessToken(): ?string {
    $record = $this->tokenStore->getAll();
    if (empty($record[OAuthTokenStore::ACCESS_TOKEN])) {
      return NULL;
    }
    $decision = $this->getRefreshDecision($record, self::EXPIRY_MARGIN, FALSE);
    if ($decision === 'none') {
      return $record[OAuthTokenStore::ACCESS_TOKEN];
    }
    if ($decision === 'defer') {
      return $this->getUsableAccessToken($record);
    }
    return $this->refreshIfAllowed($record, self::EXPIRY_MARGIN);
  }

  /**
   * Refreshes from cron 30 days before expiry, or at half a short lifetime.
   *
   * @return bool
   *   TRUE when the tokens were rotated.
   */
  public function refreshFromCron(): bool {
    $record = $this->tokenStore->getAll();
    if (empty($record[OAuthTokenStore::REFRESH_TOKEN])) {
      return FALSE;
    }
    $margin = $this->getCronMargin($record);
    if ($this->getRefreshDecision($record, $margin, TRUE) !== 'refresh') {
      return FALSE;
    }
    $this->refreshIfAllowed($record, $margin);
    return $this->tokenStore->get(OAuthTokenStore::ACCESS_TOKEN) !== $record[OAuthTokenStore::ACCESS_TOKEN];
  }

  /**
   * Why the editor must log in again, or NULL when the connection works.
   *
   * @return string|null
   *   One of the RECONNECT_* constants, or NULL.
   */
  public function getReconnectReason(): ?string {
    $record = $this->tokenStore->getAll();
    if (empty($record[OAuthTokenStore::ACCESS_TOKEN])) {
      return NULL;
    }
    if ($this->isRecordFromAnotherSite($record)) {
      return self::RECONNECT_OTHER_SITE;
    }
    if (!empty($record[OAuthTokenStore::REFUSAL])) {
      return self::RECONNECT_REFUSED;
    }
    return $this->isExpiring($record, 0) ? self::RECONNECT_EXPIRED : NULL;
  }

  /**
   * Decides whether to refresh now: 'refresh', 'none' or 'defer'.
   */
  protected function getRefreshDecision(array $record, int $margin, bool $cron): string {
    $pending = $this->getPendingState($record);
    if ($pending !== 'none') {
      return $pending === 'retry' ? 'refresh' : 'defer';
    }
    if (!$this->isExpiring($record, $margin)) {
      return 'none';
    }
    $name = $cron ? OAuthTokenStore::CRON_RETRY_AFTER : OAuthTokenStore::RETRY_AFTER;
    return (int) ($record[$name] ?? 0) > time() ? 'defer' : 'refresh';
  }

  /**
   * The state of a refresh with no answer: 'none', 'wait' or 'retry'.
   */
  protected function getPendingState(array $record): string {
    $pending = $this->getCurrentPending($record);
    if ($pending === NULL) {
      return 'none';
    }
    $age = time() - (int) ($pending['since'] ?? 0);
    if ($age < self::PENDING_MIN_AGE || (int) ($pending['attempts'] ?? 1) > self::PENDING_MAX_RETRIES) {
      return 'wait';
    }
    return 'retry';
  }

  /**
   * The pending marker, when it is for the stored refresh token and recent.
   */
  protected function getCurrentPending(array $record): ?array {
    $pending = $record[OAuthTokenStore::PENDING] ?? NULL;
    $refresh = (string) ($record[OAuthTokenStore::REFRESH_TOKEN] ?? '');
    if (!is_array($pending) || $refresh === '' || (int) ($pending['since'] ?? 0) < time() - self::PENDING_WINDOW) {
      return NULL;
    }
    return hash_equals((string) ($pending['token'] ?? ''), hash('sha256', $refresh)) ? $pending : NULL;
  }

  /**
   * The cron margin: 30 days, or half the lifetime of a shorter token.
   */
  protected function getCronMargin(array $record): int {
    $lifetime = (int) ($record[OAuthTokenStore::LIFETIME] ?? 0);
    return $lifetime > 0 ? min(self::CRON_MARGIN, intdiv($lifetime, 2)) : self::CRON_MARGIN;
  }

  /**
   * Refreshes under the lock unless a blocker or a transaction prevents it.
   */
  protected function refreshIfAllowed(array $record, int $margin): ?string {
    if ($this->getRefreshBlocker($record) !== NULL || $this->database->inTransaction()) {
      // In a transaction the lock row is not visible to other processes and
      // a rollback would discard the rotated pair. Cron refreshes later.
      return $this->getUsableAccessToken($record);
    }
    $seen_refresh = (string) $record[OAuthTokenStore::REFRESH_TOKEN];
    if (!$this->acquireRefreshLock($margin)) {
      return $this->getUsableAccessToken($this->tokenStore->getAll());
    }
    try {
      return $this->refreshUnderLock($seen_refresh, $margin);
    }
    finally {
      $this->lock->release(self::REFRESH_LOCK_ID);
    }
  }

  /**
   * Why a refresh must not be sent, or NULL.
   */
  protected function getRefreshBlocker(array $record): ?string {
    if (empty($record[OAuthTokenStore::REFRESH_TOKEN])) {
      return self::RECONNECT_EXPIRED;
    }
    if ($this->isRecordFromAnotherSite($record)) {
      return self::RECONNECT_OTHER_SITE;
    }
    return empty($record[OAuthTokenStore::REFUSAL]) ? NULL : self::RECONNECT_REFUSED;
  }

  /**
   * Takes the refresh lock, or waits for a peer that has it.
   */
  protected function acquireRefreshLock(int $margin): bool {
    if ($this->lock->acquire(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_TIMEOUT)) {
      return TRUE;
    }
    $this->lock->wait(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_WAIT);
    if (!$this->isExpiring($this->tokenStore->getAll(), $margin)) {
      return FALSE;
    }
    return $this->lock->acquire(self::REFRESH_LOCK_ID, self::REFRESH_LOCK_TIMEOUT);
  }

  /**
   * Re-reads the store under the lock and refreshes if still needed.
   */
  protected function refreshUnderLock(string $seen_refresh, int $margin): ?string {
    $record = $this->tokenStore->getAll();
    if (!$this->isRefreshStillNeeded($seen_refresh, $record, $margin)) {
      return $this->getUsableAccessToken($record);
    }
    $result = $this->sendRefresh((string) $record[OAuthTokenStore::REFRESH_TOKEN]);
    if ($result['tokens']) {
      return $this->saveRotatedTokens($result['tokens'], $record);
    }
    if ($result['outcome'] === 'refused') {
      $this->recordRefusal($result['error']);
    }
    return $this->getUsableAccessToken($this->tokenStore->getAll());
  }

  /**
   * Whether the refresh is still needed after the lock was taken.
   */
  protected function isRefreshStillNeeded(string $seen_refresh, array $record, int $margin): bool {
    $refresh = (string) ($record[OAuthTokenStore::REFRESH_TOKEN] ?? '');
    if ($refresh === '' || !hash_equals($seen_refresh, $refresh) || $this->getRefreshBlocker($record) !== NULL) {
      return FALSE;
    }
    $pending = $this->getPendingState($record);
    if ($pending !== 'none') {
      return $pending === 'retry';
    }
    return $this->isExpiring($record, $margin);
  }

  /**
   * Sends the refresh grant once, with the pending marker and back-off.
   *
   * @return array
   *   tokens (or NULL), outcome ('ok', 'rotated', 'refused', 'unknown' or
   *   'failed') and error.
   */
  protected function sendRefresh(string $refresh_token): array {
    $this->keepRunning();
    $previous = $this->getCurrentPending([
      OAuthTokenStore::PENDING => $this->tokenStore->get(OAuthTokenStore::PENDING),
      OAuthTokenStore::REFRESH_TOKEN => $refresh_token,
    ]);
    $this->tokenStore->set(OAuthTokenStore::PENDING, [
      'since' => $previous ? (int) $previous['since'] : time(),
      'token' => hash('sha256', $refresh_token),
      'attempts' => $previous ? (int) ($previous['attempts'] ?? 1) + 1 : 1,
    ]);
    $result = $this->requestRefresh($refresh_token);
    if (in_array($result['outcome'], ['refused', 'failed'], TRUE)) {
      $this->tokenStore->delete([OAuthTokenStore::PENDING]);
    }
    if (in_array($result['outcome'], ['unknown', 'rotated'], TRUE)) {
      $this->tokenStore->setMany([
        OAuthTokenStore::RETRY_AFTER => time() + self::REQUEST_BACKOFF,
        OAuthTokenStore::CRON_RETRY_AFTER => time() + self::CRON_BACKOFF,
      ]);
    }
    return $result;
  }

  /**
   * Posts the refresh grant and classifies the answer.
   */
  protected function requestRefresh(string $refresh_token): array {
    try {
      $response = $this->httpClient->request('POST', $this->getInternalDashboardUrl() . '/oauth/token', [
        'form_params' => [
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
          'client_id' => Platform::CLIENT_ID,
        ],
        'headers' => ['Accept' => 'application/json'],
        'timeout' => self::REFRESH_HTTP_TIMEOUT,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Exception $e) {
      // A timeout can come after the portal committed the rotation.
      $this->logger->error('The Quant token refresh got no answer (@class). The stored tokens are kept.', ['@class' => get_class($e)]);
      return ['tokens' => NULL, 'outcome' => 'unknown', 'error' => ''];
    }
    return $this->parseRefreshResponse($response);
  }

  /**
   * Turns a refresh response into a result array.
   */
  protected function parseRefreshResponse(ResponseInterface $response): array {
    $status = $response->getStatusCode();
    $data = json_decode((string) $response->getBody(), TRUE);
    $data = is_array($data) ? $data : [];
    if ($status === 200 && !empty($data['access_token'])) {
      return ['tokens' => $data, 'outcome' => 'ok', 'error' => ''];
    }
    $error = (string) ($data['error'] ?? '');
    $description = mb_substr((string) ($data['error_description'] ?? ''), 0, 200);
    $outcome = self::classifyRefreshError($status, $error, $description);
    if ($outcome !== 'refused') {
      $this->logger->log($outcome === 'rotated' ? LogLevel::NOTICE : LogLevel::ERROR, 'The Quant token refresh was rejected with status @status (@error). The stored tokens are kept.', [
        '@status' => $status,
        '@error' => $error ?: 'none',
      ]);
    }
    return ['tokens' => NULL, 'outcome' => $outcome, 'error' => $error];
  }

  /**
   * Classifies a refresh error: 'rotated', 'refused', 'unknown' or 'failed'.
   */
  public static function classifyRefreshError(int $status, string $error, string $description): string {
    if ($error === 'invalid_grant' && stripos($description, 'just rotated') !== FALSE) {
      return 'rotated';
    }
    if (in_array($error, ['invalid_grant', 'invalid_client'], TRUE)) {
      return 'refused';
    }
    return ($status >= 500 || $status === 200) ? 'unknown' : 'failed';
  }

  /**
   * Keeps the process alive so a rotated pair is always saved.
   */
  protected function keepRunning(): void {
    if (function_exists('ignore_user_abort')) {
      ignore_user_abort(TRUE);
    }
    Environment::setTimeLimit(0);
  }

  /**
   * Saves a rotated pair and clears the refresh markers.
   */
  protected function saveRotatedTokens(array $token_data, array $previous): ?string {
    try {
      $this->tokenStore->saveTokens($token_data);
      $this->tokenStore->delete([
        OAuthTokenStore::PENDING,
        OAuthTokenStore::RETRY_AFTER,
        OAuthTokenStore::CRON_RETRY_AFTER,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->critical('The refreshed Quant tokens could not be saved (@class). The refresh is retried later.', ['@class' => get_class($e)]);
      return $this->getUsableAccessToken($previous);
    }
    return $token_data['access_token'];
  }

  /**
   * Records a final refusal: no more refresh until the next login.
   */
  protected function recordRefusal(string $error): void {
    $this->tokenStore->set(OAuthTokenStore::REFUSAL, ['error' => $error, 'at' => time()]);
    $this->logger->error('Quant refused the token refresh (@error). Log in to Quant again on the Quant API settings page.', ['@error' => $error]);
  }

  /**
   * The stored access token while it has not expired.
   */
  protected function getUsableAccessToken(array $record): ?string {
    $access = $record[OAuthTokenStore::ACCESS_TOKEN] ?? NULL;
    if (!$access || $this->isExpiring($record, 0)) {
      return NULL;
    }
    return $access;
  }

  /**
   * Whether the stored tokens were issued to another site.
   */
  protected function isRecordFromAnotherSite(array $record): bool {
    $stored = $record[OAuthTokenStore::FINGERPRINT] ?? NULL;
    return is_array($stored) && $this->fingerprint->differences($stored) !== [];
  }

  /**
   * Whether the access token expires within the margin.
   */
  protected function isExpiring(array $record, int $margin): bool {
    $expires_at = (int) ($record[OAuthTokenStore::EXPIRES_AT] ?? 0);
    return $expires_at > 0 && $expires_at - $margin <= time();
  }

  /**
   * Base64url without padding.
   */
  protected static function base64Url(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

}
