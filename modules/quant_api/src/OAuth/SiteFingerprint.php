<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;

/**
 * Identifies this site, so that a database copy does not refresh the tokens.
 *
 * The portal rotates the refresh token on each use and revokes the whole
 * chain when an old one is replayed. A staging copy of the production
 * database that refreshed "its" tokens would therefore disconnect production.
 * The stored fingerprint lets the copy see that the tokens are not its own.
 *
 * On a copy, the login is neither refreshed nor used to publish
 * (Credentials::getToken() returns NULL), so staging content never reaches
 * the production project. An administrator who knows it is the same site (a
 * new database name, a new hash salt) confirms it with "This is the same
 * site" on the Quant API settings page, which stores the fingerprint again.
 */
class SiteFingerprint {

  /**
   * Hosting variables that differ between environments of one site.
   */
  public const ENVIRONMENT_VARIABLES = [
    'PLATFORM_ENVIRONMENT',
    'LAGOON_ENVIRONMENT',
    'PANTHEON_ENVIRONMENT',
    'AH_SITE_ENVIRONMENT',
    'QUANT_ENVIRONMENT_NAME',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The hashed components of this site's fingerprint, keyed by component.
   *
   * A $settings['quant_api_site_id'] replaces the automatic components.
   */
  public function compute(): array {
    $site_id = (string) Settings::get('quant_api_site_id', '');
    $values = $site_id !== '' ? ['override' => $site_id] : $this->automaticValues();
    $values['uuid'] = (string) $this->configFactory->get('system.site')->get('uuid');

    $hashes = [];
    foreach ($values as $name => $value) {
      $hashes[$name] = hash('sha256', $name . "\0" . $value);
    }
    return $hashes;
  }

  /**
   * The components that differ between a stored fingerprint and this site.
   *
   * @return string[]
   *   The names of the differing components; empty when it is this site.
   */
  public function differences(array $stored): array {
    $current = $this->compute();
    $different = [];
    foreach (array_keys($stored + $current) as $name) {
      if ($this->differs($name, $stored, $current)) {
        $different[] = $name;
      }
    }
    return $different;
  }

  /**
   * Whether one component differs.
   *
   * A hosting variable counts only when both sides have it, so a variable
   * set for one context only (web, not CLI) does not split one site in two.
   */
  protected function differs(string $name, array $stored, array $current): bool {
    if (str_starts_with($name, 'env:') && (!isset($stored[$name]) || !isset($current[$name]))) {
      return FALSE;
    }
    return !hash_equals((string) ($stored[$name] ?? ''), (string) ($current[$name] ?? ''));
  }

  /**
   * The automatic components: salt, database, prefix and hosting variables.
   */
  protected function automaticValues(): array {
    $db = Database::getConnectionInfo()['default'] ?? [];
    $prefix = $db['prefix'] ?? '';
    $values = [
      'hash_salt' => (string) Settings::get('hash_salt', ''),
      'database' => (string) ($db['database'] ?? ''),
      'prefix' => is_array($prefix) ? (string) ($prefix['default'] ?? '') : (string) $prefix,
    ];
    foreach (self::ENVIRONMENT_VARIABLES as $variable) {
      $value = getenv($variable);
      if (is_string($value) && $value !== '') {
        $values['env:' . $variable] = $value;
      }
    }
    return $values;
  }

}
