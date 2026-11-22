<?php

namespace Drupal\quant_webform;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\quant_api\Credentials;
use Drupal\quant_api\OAuth\AuthService;
use Drupal\quant_api\OAuth\PortalClient;

/**
 * The alert channels of the organisation, read with the Quant login.
 *
 * A channel is an email list or a Slack destination kept in the Quant
 * dashboard. A form that uses channels sends no addresses or webhooks of its
 * own. The hint of a channel (a masked address or a Slack channel name) is
 * for display only: it never goes to config or to a log.
 */
class QuantAlertChannels {

  /**
   * The channels were read.
   */
  public const OK = 'ok';

  /**
   * The site connects with a project token only: no login to read with.
   */
  public const NOT_CONNECTED = 'not_connected';

  /**
   * The login cannot read the channels: log in to Quant again.
   */
  public const RECONNECT = 'reconnect';

  /**
   * The answers that mean the login must be renewed.
   */
  protected const RECONNECT_STATUSES = [401, 403];

  /**
   * The dashboard did not answer, or answered with an error.
   */
  public const UNAVAILABLE = 'unavailable';

  /**
   * The ID of a channel.
   */
  public const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

  public function __construct(
    protected AuthService $auth,
    protected PortalClient $portal,
    protected Credentials $credentials,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The channels, and whether they could be read.
   *
   * @return array
   *   - state: one of the state constants.
   *   - channels: a list of ['id', 'name', 'type', 'hint'], empty unless the
   *     state is "ok".
   */
  public function load(): array {
    try {
      return $this->read();
    }
    catch (\Throwable $e) {
      return self::result(self::UNAVAILABLE);
    }
  }

  /**
   * Whether a value is a valid channel ID.
   */
  public static function isId(mixed $value): bool {
    return is_string($value) && preg_match(self::ID_PATTERN, $value) === 1;
  }

  /**
   * Reads the channels as the module's other OAuth calls do.
   */
  protected function read(): array {
    $config = $this->configFactory->get('quant_api.settings');
    if (!$this->credentials->usesOauth($config)) {
      return self::result(self::NOT_CONNECTED);
    }
    // A copied site holds another site's login: it must log in on its own.
    $access = $this->credentials->isCopiedSite() ? NULL : $this->auth->getValidAccessToken();
    if ($access === NULL) {
      return self::result(self::RECONNECT);
    }
    $answer = $this->portal->listAlertChannels($access, (string) $config->get('api_account'), (string) $config->get('api_project'));
    if (in_array($answer['status'], self::RECONNECT_STATUSES, TRUE)) {
      return self::result(self::RECONNECT);
    }
    if ($answer['status'] !== 200) {
      return self::result(self::UNAVAILABLE);
    }
    return self::result(self::OK, self::rows($answer['channels']));
  }

  /**
   * The valid rows of the answer, as strings.
   */
  protected static function rows(array $rows): array {
    $channels = [];
    foreach ($rows as $row) {
      if (!is_array($row) || !self::isId($row['id'] ?? NULL)) {
        continue;
      }
      $channels[] = [
        'id' => $row['id'],
        'name' => is_scalar($row['name'] ?? NULL) ? (string) $row['name'] : $row['id'],
        'type' => is_scalar($row['type'] ?? NULL) ? (string) $row['type'] : '',
        'hint' => is_scalar($row['hint'] ?? NULL) ? (string) $row['hint'] : '',
      ];
    }
    return $channels;
  }

  /**
   * A result of load().
   */
  protected static function result(string $state, array $channels = []): array {
    return ['state' => $state, 'channels' => $channels];
  }

}
