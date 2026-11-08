<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Stores the OAuth connection outside configuration.
 *
 * Nothing here is exportable config, so a config export or a config sync
 * never carries a token. Two stores:
 *
 * - The connection (KeyValue "quant_api.oauth"): the access and refresh
 *   tokens of a token limited to one project, and that project's write token.
 * - The pending login (expirable KeyValue "quant_api.oauth_pending", keyed by
 *   user id): the tokens between the callback and the project choice. Those
 *   tokens are not yet limited to a project, so they expire after
 *   PENDING_TTL seconds and never become the connection.
 */
class OAuthTokenStore {

  public const COLLECTION = 'quant_api.oauth';

  public const PENDING_COLLECTION = 'quant_api.oauth_pending';

  /**
   * How long an unlimited login waits for the project choice, in seconds.
   */
  public const PENDING_TTL = 900;

  public const ACCESS_TOKEN = 'access_token';

  public const REFRESH_TOKEN = 'refresh_token';

  public const EXPIRES_AT = 'expires_at';

  public const LIFETIME = 'lifetime';

  public const WRITE_TOKEN = 'write_token';

  public const USER = 'user';

  public const FINGERPRINT = 'fingerprint';

  public const REFUSAL = 'refusal';

  public const RETRY_AFTER = 'retry_after';

  public const CRON_RETRY_AFTER = 'cron_retry_after';

  public const PENDING = 'pending';

  public const SYNCED_AT = 'synced_at';

  public const UNAUTHORIZED_AT = 'unauthorized_at';

  protected const NAMES = [
    self::ACCESS_TOKEN,
    self::REFRESH_TOKEN,
    self::EXPIRES_AT,
    self::LIFETIME,
    self::WRITE_TOKEN,
    self::USER,
    self::FINGERPRINT,
    self::REFUSAL,
    self::RETRY_AFTER,
    self::CRON_RETRY_AFTER,
    self::PENDING,
    self::SYNCED_AT,
    self::UNAUTHORIZED_AT,
  ];

  /**
   * The connection store.
   */
  protected KeyValueStoreInterface $store;

  /**
   * The pending login store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected $pending;

  public function __construct(KeyValueFactoryInterface $key_value_factory, KeyValueExpirableFactoryInterface $expirable_factory) {
    $this->store = $key_value_factory->get(self::COLLECTION);
    $this->pending = $expirable_factory->get(self::PENDING_COLLECTION);
  }

  /**
   * All stored connection values, keyed by name.
   */
  public function getAll(): array {
    return $this->store->getMultiple(self::NAMES);
  }

  /**
   * One stored connection value.
   */
  public function get(string $name): mixed {
    return $this->store->get($name);
  }

  /**
   * Sets one connection value.
   */
  public function set(string $name, mixed $value): void {
    $this->store->set($name, $value);
  }

  /**
   * Sets several connection values.
   */
  public function setMany(array $values): void {
    $this->store->setMultiple($values);
  }

  /**
   * Deletes the named connection values.
   */
  public function delete(array $names): void {
    $this->store->deleteMultiple($names);
  }

  /**
   * Whether a connection (a limited token and a write token) is stored.
   */
  public function isConnected(): bool {
    return (string) $this->store->get(self::WRITE_TOKEN) !== '';
  }

  /**
   * Saves an access and refresh token from a token response.
   *
   * @param array $token_data
   *   The decoded token response: access_token, refresh_token, expires_in.
   */
  public function saveTokens(array $token_data): void {
    $values = [self::ACCESS_TOKEN => (string) $token_data['access_token']];
    if (!empty($token_data['refresh_token'])) {
      $values[self::REFRESH_TOKEN] = (string) $token_data['refresh_token'];
    }
    $expires_in = (int) ($token_data['expires_in'] ?? 0);
    if ($expires_in > 0) {
      $values[self::EXPIRES_AT] = time() + $expires_in;
      $values[self::LIFETIME] = $expires_in;
    }
    $this->store->setMultiple($values);
    if ($expires_in <= 0) {
      $this->store->deleteMultiple([self::EXPIRES_AT, self::LIFETIME]);
    }
  }

  /**
   * Deletes the whole connection.
   */
  public function clear(): void {
    $this->store->deleteMultiple(self::NAMES);
  }

  /**
   * Keeps a login that waits for the project choice.
   *
   * @param int $uid
   *   The user who logged in.
   * @param array $login
   *   The token response, the user and the granted organisations.
   */
  public function savePendingLogin(int $uid, array $login): void {
    $this->pending->setWithExpire((string) $uid, $login, self::PENDING_TTL);
  }

  /**
   * The login that waits for this user's project choice, if any.
   */
  public function getPendingLogin(int $uid): ?array {
    $login = $this->pending->get((string) $uid);
    return is_array($login) ? $login : NULL;
  }

  /**
   * Forgets a pending login.
   */
  public function deletePendingLogin(int $uid): void {
    $this->pending->delete((string) $uid);
  }

  /**
   * Forgets every pending login (uninstall).
   */
  public function deleteAllPendingLogins(): void {
    $this->pending->deleteAll();
  }

}
