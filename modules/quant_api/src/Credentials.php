<?php

namespace Drupal\quant_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\StorageInterface;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drupal\quant_api\OAuth\Platform;
use Drupal\quant_api\OAuth\SiteFingerprint;

/**
 * Resolves the project write token that the content API calls send.
 *
 * Order:
 * 1. A settings.php override of quant_api.settings:api_token (CI, hosting).
 * 2. The OAuth connection, when auth_method is "oauth".
 * 3. The token saved with "Connect with a project token".
 *
 * Read the token through this service, never from config: an OAuth
 * connection keeps its token outside config.
 */
class Credentials {

  public const METHOD_TOKEN = 'token';

  public const METHOD_OAUTH = 'oauth';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected OAuthTokenStore $tokenStore,
    protected StorageInterface $configStorage,
    protected SiteFingerprint $fingerprint,
  ) {}

  /**
   * Whether the stored login was made on another site (a copied database).
   *
   * A copy must not publish with the original site's project token: staging
   * edits would reach the production project.
   */
  public function isCopiedSite(): bool {
    $stored = $this->tokenStore->get(OAuthTokenStore::FINGERPRINT);
    return is_array($stored) && $this->fingerprint->differences($stored) !== [];
  }

  /**
   * The content API endpoint, without /v1.
   *
   * With "Log in to Quant" the endpoint is the chosen platform's official
   * host, so the hidden write token never goes elsewhere. A settings.php
   * override still wins.
   */
  public function getEndpoint(?Config $config = NULL): string {
    $config = $config ?? $this->configFactory->get('quant_api.settings');
    if ($this->usesOauth($config) && !$config->hasOverrides('api_endpoint')) {
      return Platform::apiEndpoint((string) $config->get('platform'));
    }
    return (string) $config->get('api_endpoint');
  }

  /**
   * Whether a domain overrides the Quant API settings (a multi-domain site).
   *
   * Such a site publishes each domain to its own project. "Log in to Quant"
   * stores one connection, so these sites use token connect for now.
   */
  public function hasDomainOverrides(): bool {
    foreach ($this->configStorage->listAll('domain.config.') as $name) {
      if (str_ends_with($name, '.quant_api.settings')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The write token for the given (possibly domain-overridden) settings.
   */
  public function getToken(?Config $config = NULL): ?string {
    $config = $config ?? $this->configFactory->get('quant_api.settings');
    if ($config->hasOverrides('api_token')) {
      return ((string) $config->get('api_token')) ?: NULL;
    }
    if ($this->usesOauth($config)) {
      if ($this->isCopiedSite()) {
        return NULL;
      }
      return ((string) $this->tokenStore->get(OAuthTokenStore::WRITE_TOKEN)) ?: NULL;
    }
    return ((string) $config->get('api_token')) ?: NULL;
  }

  /**
   * Whether a token is available, without returning it.
   */
  public function hasToken(?Config $config = NULL): bool {
    return $this->getToken($config) !== NULL;
  }

  /**
   * Whether the site connects with "Log in to Quant".
   */
  public function usesOauth(?Config $config = NULL): bool {
    $config = $config ?? $this->configFactory->get('quant_api.settings');
    return $config->get('auth_method') === self::METHOD_OAUTH;
  }

}
