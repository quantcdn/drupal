<?php

namespace Drupal\quant_purger\Entity;

use Drupal\purge\Plugin\Purge\Purger\PurgerSettingsBase;
use Drupal\purge\Plugin\Purge\Purger\PurgerSettingsInterface;

/**
 * Defines the QuantPurgerSettings entity.
 *
 * @ConfigEntityType(
 *   id = "quant_purger_settings",
 *   label = @Translation("Quant Purger Settings"),
 *   config_prefix = "settings",
 *   static_cache = TRUE,
 *   entity_keys = {"id" = "id"},
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "name",
 *     "invalidation_type",
 *     "runtime_measurement",
 *     "timeout",
 *     "connect_timeout",
 *     "cooldown_time",
 *     "max_requests",
 *     "http_errors"
 *   },
 * )
 */
class QuantPurgerSettings extends PurgerSettingsBase implements PurgerSettingsInterface {

  /**
   * Instance metadata.
   */

  /**
   * The readable name of this purger.
   *
   * @var string
   */
  public $name = 'Quant Purger';

  /**
   * The invalidation plugin ID that this purger invalidates.
   *
   * @var string
   */
  // @phpcs:ignore
  public $invalidation_type = 'tag';

  /**
   * Runtime measurement.
   *
   * When FALSE, dynamic capacity calculation will be disabled and based upon
   * the connect_timeout and timeout settings.
   *
   * @var bool
   */
  // @phpcs:ignore
  public $runtime_measurement = TRUE;

  /**
   * The timeout of the request in seconds.
   *
   * @var float
   */
  public $timeout = 1.0;

  /**
   * The number of seconds to wait while trying to connect to a server.
   *
   * @var float
   */
  // @phpcs:ignore
  public $connect_timeout = 1.0;

  /**
   * Cooldown time.
   *
   * Number of seconds to wait after one or more invalidations took place (so
   * that other purgers get fresh content).
   *
   * @var float
   */
  // @phpcs:ignore
  public $cooldown_time = 0.0;

  /**
   * Maximum requests.
   *
   * Maximum number of HTTP requests that can be made during Drupal's execution
   * lifetime. Usually PHP resource restraints lower this value dynamically, but
   * can be met at the CLI.
   *
   * @var int
   */
  // @phpcs:ignore
  public $max_requests = 250;

  /**
   * Success resolution.
   */

  /**
   * Whether 4xx and 5xx responses need to be treated as failures or not.
   *
   * @var bool
   *
   * @see http://docs.guzzlephp.org/en/latest/request-options.html#http-errors
   */
  // @phpcs:ignore
  public $http_errors = TRUE;

}
