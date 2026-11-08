<?php

namespace Drupal\quant_api\OAuth;

use Drupal\Core\Site\Settings;

/**
 * The Quant platforms: their content API endpoint and their dashboard.
 *
 * QuantCDN and QuantGov are separate platforms with separate accounts. The
 * dashboard runs the OAuth server; the API endpoint takes content.
 */
final class Platform {

  public const QUANTCDN = 'quantcdn';

  public const QUANTGOV = 'quantgov';

  /**
   * The API endpoint and dashboard of each platform.
   */
  protected const PLATFORMS = [
    self::QUANTCDN => [
      'api' => 'https://api.quantcdn.io',
      'dashboard' => 'https://dashboard.quantcdn.io',
    ],
    self::QUANTGOV => [
      'api' => 'https://api.quantgov.cloud',
      'dashboard' => 'https://dash.quantgov.cloud',
    ],
  ];

  /**
   * The OAuth client id of this module on every platform.
   */
  public const CLIENT_ID = 'drupal-quant';

  /**
   * The known platform ids.
   *
   * @return string[]
   *   The ids.
   */
  public static function ids(): array {
    return array_keys(self::PLATFORMS);
  }

  /**
   * Whether the id names a known platform.
   */
  public static function isKnown(?string $platform): bool {
    return isset(self::PLATFORMS[(string) $platform]);
  }

  /**
   * The content API endpoint of a platform, without /v1.
   */
  public static function apiEndpoint(string $platform): string {
    return self::PLATFORMS[$platform]['api'] ?? self::PLATFORMS[self::QUANTCDN]['api'];
  }

  /**
   * The dashboard (OAuth server) of a platform.
   *
   * A $settings['quant_api_dashboard_url'] override wins, for local and
   * staging dashboards.
   */
  public static function dashboardUrl(string $platform): string {
    $override = (string) Settings::get('quant_api_dashboard_url', '');
    if ($override !== '') {
      return rtrim($override, '/');
    }
    return self::PLATFORMS[$platform]['dashboard'] ?? self::PLATFORMS[self::QUANTCDN]['dashboard'];
  }

  /**
   * The dashboard for server-to-server calls (token, refresh, projects).
   *
   * $settings['quant_api_dashboard_internal_url'] wins, for a site whose
   * server reaches the dashboard at another address than the browser does
   * (for example a container that reaches the host at host.docker.internal).
   * The browser redirect always uses dashboardUrl().
   */
  public static function internalDashboardUrl(string $platform): string {
    $internal = (string) Settings::get('quant_api_dashboard_internal_url', '');
    return $internal !== '' ? rtrim($internal, '/') : self::dashboardUrl($platform);
  }

  /**
   * The platform of an API endpoint, for sites configured before 2.3.
   *
   * @return string
   *   The platform id. An endpoint on quantgov.cloud is QuantGov; any other
   *   endpoint is QuantCDN.
   */
  public static function fromEndpoint(?string $endpoint): string {
    $host = (string) parse_url((string) $endpoint, PHP_URL_HOST);
    return ($host === 'quantgov.cloud' || str_ends_with($host, '.quantgov.cloud')) ? self::QUANTGOV : self::QUANTCDN;
  }

}
