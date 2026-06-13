<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Generates on-demand CSS/JS aggregates so they can be pushed to Quant.
 *
 * Since Drupal 10.1 asset aggregates are generated on demand: the file is
 * only written to disk when the asset route is requested with its query
 * string AND the filename hash matches current library definitions. On
 * mismatch the asset controller responds with a 301 to the corrected
 * filename and the requested file never appears on disk.
 *
 * The static copy served by Quant references the filename in the synced
 * markup, so the asset must exist under that exact path. This service
 * requests the aggregate from the local server (following the controller's
 * hash-correction redirect) and persists the response body at the expected
 * location when core has not dumped it.
 */
class AssetGenerator {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * AssetGenerator constructor.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
  }

  /**
   * Ensure an on-demand asset exists on disk at the expected location.
   *
   * @param string $original_path
   *   The asset path as referenced in markup, including the query string
   *   required by the asset controller (delta, language, theme, include).
   * @param string $file_on_disk
   *   The absolute path the asset is expected at.
   *
   * @return bool
   *   TRUE if the file exists on disk after generation.
   */
  public function generate(string $original_path, string $file_on_disk) : bool {
    if (file_exists($file_on_disk)) {
      return TRUE;
    }

    $config = $this->configFactory->get('quant.settings');
    $local_server = $config->get('local_server') ?: 'http://localhost';
    $hostname = $config->get('host_domain') ?: ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // Support basic auth if enabled (note: will not work via drush/cli).
    $auth = !empty($_SERVER['PHP_AUTH_USER']) ? [
      $_SERVER['PHP_AUTH_USER'],
      $_SERVER['PHP_AUTH_PW'],
    ] : [];

    try {
      // Redirects must be followed: on a filename hash mismatch the asset
      // controller 301s to the corrected filename, which generates the
      // aggregate and returns its content.
      $response = $this->httpClient->request('GET', $local_server . $original_path, [
        'http_errors' => FALSE,
        'headers' => ['Host' => $hostname],
        'auth' => $auth,
        'allow_redirects' => TRUE,
        'verify' => boolval($config->get('ssl_cert_verify')),
      ]);
    }
    catch (\Exception $error) {
      return FALSE;
    }

    // When the filename hash matched, core dumped the file itself.
    clearstatcache(TRUE, $file_on_disk);
    if (file_exists($file_on_disk)) {
      return TRUE;
    }

    if ($response->getStatusCode() != 200) {
      return FALSE;
    }

    $content = (string) $response->getBody();
    if ($content === '') {
      return FALSE;
    }

    $directory = dirname($file_on_disk);
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE)) {
      return FALSE;
    }

    return file_put_contents($file_on_disk, $content) !== FALSE;
  }

}
