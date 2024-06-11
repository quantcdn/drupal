<?php

namespace Drupal\quant_purger\Plugin\Purge\Purger;

use Drupal\Core\Utility\Token;
use GuzzleHttp\ClientInterface;
use Drupal\purge\Plugin\Purge\Purger\PurgerBase;
use Drupal\purge\Plugin\Purge\Purger\PurgerInterface;
use Drupal\quant_purger\Entity\QuantPurgeSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;

/**
 * Abstract base class for HTTP based configurable purgers.
 */
abstract class QuantPurgeBase extends PurgerBase implements PurgerInterface {

  /**
   * The Guzzle HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * The settings entity holding all configuration.
   *
   * @var \Drupal\quant_purger\Entity\QuantPurgeSettings
   */
  protected $settings;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Constructs the HTTP purger.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   An HTTP client that can perform remote requests.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->settings = QuantPurgeSettings::load($this->getId());
    // Note: We use the Quant HTTP client rather than the generic Guzzle client.
    $this->client = \Drupal::service('quant_api.client');
    $this->token = $token;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    QuantPurgeSettings::load($this->getId())->delete();
  }

  /**
   * {@inheritdoc}
   */
  public function getCooldownTime() {
    return $this->settings->cooldown_time;
  }

  /**
   * {@inheritdoc}
   */
  public function getIdealConditionsLimit() {
    return $this->settings->max_requests;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    if ($this->settings->name) {
      return $this->settings->name;
    }
    else {
      return parent::getLabel();
    }
  }

  /**
   * Returns array of tags and paths to purge in the provided array.
   *
   * @param array $invalidations
   *   The invalidations array.
   *
   * @return array
   *   The array of filtered tags and paths.
   */
  public function processInvalidations(array $invalidations) {
    $filtered_tags = [];
    $filtered_paths = [];

    $everything = FALSE;

    foreach ($invalidations as $invalidation) {

      if ($invalidation->getType() == 'tag') {
        $invalidation->setState(InvalidationInterface::PROCESSING);
        $filtered_tags[] = $invalidation;
      }
      elseif ($invalidation->getType() == 'path') {
        // @todo Not sure what this looks like.
        $invalidation->setState(InvalidationInterface::PROCESSING);
        $filtered_paths[] = $invalidation;
      }
      elseif ($invalidation->getType() == 'everything') {
        // 'Everything' trumps everything and will issue a site-wide purge.
        $invalidation->setState(InvalidationInterface::PROCESSING);
        $everything = TRUE;
        $filtered_paths = [];
        $filtered_tags = [];
        break;
      }
      else {
        $invalidation->setState(InvalidationInterface::NOT_SUPPORTED);
      }
    }

    $filtered_array = [
      'everything' => $everything,
      'tags' => $filtered_tags,
      'paths' => $filtered_paths,
    ];

    return $filtered_array;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeHint() {
    // When runtime measurement is enabled, we just use the base implementation.
    if ($this->settings->runtime_measurement) {
      return parent::getTimeHint();
    }
    // Theoretically connection timeouts and general timeouts can add up, so
    // we add up our assumption of the worst possible time it takes as well.
    return $this->settings->connect_timeout + $this->settings->timeout;
  }

  /**
   * {@inheritdoc}
   */
  public function getTypes() {
    return [
      "tag",
      "everything",
      "path",
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function hasRuntimeMeasurement() {
    return (bool) $this->settings->runtime_measurement;
  }

  /**
   * Sends a path-based purge request to the Quant API.
   *
   * @param string $path
   *   The path to purge.
   */
  public function purgePath(string $path) {
    $this->client->purgePath($path);
  }

  /**
   * Sends a tags-based purge request to the Quant API.
   *
   * @param array $tags
   *   The array of tags to purge.
   */
  public function purgeTags(array $tags) {
    $this->client->purgeTags($tags);
  }

}
