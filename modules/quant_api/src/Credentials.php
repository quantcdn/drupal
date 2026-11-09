<?php

namespace Drupal\quant_api;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;
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
    return $this->copyDifferences() !== [];
  }

  /**
   * The fingerprint components that differ from the connected site.
   *
   * @return string[]
   *   Component names (database, prefix, hash_salt, uuid, env:...), never
   *   values; empty when this is the connected site.
   */
  public function copyDifferences(): array {
    $stored = $this->tokenStore->get(OAuthTokenStore::FINGERPRINT);
    return is_array($stored) ? $this->fingerprint->differences($stored) : [];
  }

  /**
   * Confirms that this is the connected site: stores its fingerprint again.
   */
  public function confirmSameSite(): void {
    $this->tokenStore->set(OAuthTokenStore::FINGERPRINT, $this->fingerprint->compute());
  }

  /**
   * The endpoint from $settings['quant_api_endpoint_url'], or an empty string.
   *
   * Set only in settings.php, never in the UI. It may carry basic-auth
   * userinfo (https://user:password@host); the HTTP client moves that into a
   * header, and redact() keeps it off every page.
   */
  public static function endpointOverride(): string {
    return rtrim(trim((string) Settings::get('quant_api_endpoint_url', '')), '/');
  }

  /**
   * An endpoint without its userinfo, for display.
   */
  public static function redact(string $endpoint): string {
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['host'])) {
      return '';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
  }

  /**
   * The content API endpoint, without /v1.
   *
   * With "Log in to Quant" the endpoint is the chosen platform's official
   * host, so the hidden write token never goes elsewhere. A settings.php
   * override still wins.
   */
  public function getEndpoint(?Config $config = NULL): string {
    $override = self::endpointOverride();
    if ($override !== '') {
      return $override;
    }
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
