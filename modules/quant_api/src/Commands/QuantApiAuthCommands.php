<?php

namespace Drupal\quant_api\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\OAuth\AuthService;
use Drupal\quant_api\OAuth\OAuthTokenStore;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Quant API connection.
 */
class QuantApiAuthCommands extends DrushCommands {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected Credentials $credentials,
    protected AuthService $auth,
    protected OAuthTokenStore $tokenStore,
  ) {
    parent::__construct();
  }

  /**
   * Shows how this site connects to Quant. Never prints a token.
   *
   * @command quant:auth-status
   * @usage drush quant:auth-status
   *   Show the platform, the project and the connection method.
   */
  public function authStatus() {
    $config = $this->configFactory->get('quant_api.settings');
    $oauth = $this->credentials->usesOauth($config) && !$config->hasOverrides('api_token');
    $rows = [
      'Platform' => (string) $config->get('platform'),
      'API endpoint' => Credentials::redact($this->credentials->getEndpoint($config)) . (Credentials::endpointOverride() !== '' ? ' (settings.php)' : ''),
      'Organization' => (string) $config->get('api_account'),
      'Project' => (string) $config->get('api_project'),
      'Method' => $oauth ? 'Log in to Quant' : ($config->hasOverrides('api_token') ? 'Project token (settings.php)' : 'Project token'),
      'Token available' => $this->credentials->hasToken($config) ? 'yes' : 'no',
    ];
    if ($oauth) {
      $expires = (int) $this->tokenStore->get(OAuthTokenStore::EXPIRES_AT);
      $rows['Login expires'] = $expires ? date('Y-m-d H:i', $expires) : '-';
      $rows['Needs a new login'] = $this->auth->getReconnectReason() ?? 'no';
    }
    foreach ($rows as $label => $value) {
      $this->io()->writeln(sprintf('%-18s %s', $label . ':', $value));
    }
  }

}
