<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Credentials;
use Psr\Log\LoggerInterface;

/**
 * Re-reads the project write token with the login, so a rotated one recovers.
 *
 * A write token rotated in the dashboard would otherwise stop publishing
 * until someone logs in again. The login is limited to the project, so it
 * can read only that project's write token.
 */
class WriteTokenSync {

  /**
   * How often cron re-reads the write token without a refresh, in seconds.
   */
  public const INTERVAL = 86400;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  public function __construct(
    protected AuthService $auth,
    protected PortalClient $portal,
    protected OAuthTokenStore $tokenStore,
    protected Credentials $credentials,
    protected ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('quant_api');
  }

  /**
   * Syncs after a refresh, or when the last sync is a day old.
   */
  public function syncIfDue(bool $refreshed): ?bool {
    $last = (int) $this->tokenStore->get(OAuthTokenStore::SYNCED_AT);
    if (!$refreshed && $last > time() - self::INTERVAL) {
      return NULL;
    }
    return $this->sync();
  }

  /**
   * Re-reads the write token.
   *
   * @return bool|null
   *   TRUE when the token changed, FALSE when it did not, NULL when the sync
   *   could not run (no login, a copied site, or no answer).
   */
  public function sync(): ?bool {
    $config = $this->configFactory->get('quant_api.settings');
    if (!$this->credentials->usesOauth($config) || $this->credentials->isCopiedSite()) {
      return NULL;
    }
    $access = $this->auth->getValidAccessToken();
    if ($access === NULL) {
      return NULL;
    }
    $this->tokenStore->set(OAuthTokenStore::SYNCED_AT, time());
    $token = $this->portal->fetchWriteToken($access, (string) $config->get('api_account'), (string) $config->get('api_project'));
    if ($token === NULL) {
      return NULL;
    }
    if (hash_equals((string) $this->tokenStore->get(OAuthTokenStore::WRITE_TOKEN), $token)) {
      return FALSE;
    }
    $this->tokenStore->set(OAuthTokenStore::WRITE_TOKEN, $token);
    $this->logger->notice('Quant: the project write token changed in the dashboard and was updated from the login.');
    return TRUE;
  }

}
