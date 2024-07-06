<?php

namespace Drupal\quant_purger\Plugin\Purge\Purger;

use Drupal\Core\Utility\Token;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\purge\Plugin\Purge\Purger\PurgerBase;
use Drupal\purge\Plugin\Purge\Purger\PurgerInterface;
use Drupal\quant_purger\Entity\QuantPurgerSettings;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Abstract base class for Quant Purger.
 */
abstract class QuantPurgerBase extends PurgerBase implements PurgerInterface {

  /**
   * The Guzzle HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $client;

  /**
   * The settings entity holding all configuration.
   *
   * @var \Drupal\quant_purger\Entity\QuantPurgerSettings
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
    $this->settings = QuantPurgerSettings::load($this->getId());
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
    QuantPurgerSettings::load($this->getId())->delete();
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
   * Process invalidations based on type.
   *
   * @param array $invalidations
   *   The invalidations array.
   *
   * @return array
   *   The processed array with 'everything', 'paths' and 'tags' keys.
   */
  public function processInvalidations(array $invalidations) {
    $everything = FALSE;
    $paths = [];
    $tags = [];

    foreach ($invalidations as $invalidation) {

      if ($invalidation->getType() == 'everything') {
        // 'Everything' trumps everything and will issue a site-wide purge.
        $invalidation->setState(InvalidationInterface::PROCESSING);
        $everything = TRUE;
        $paths = [];
        $tags = [];
        break;
      }
      elseif ($invalidation->getType() == 'path') {
        $invalidation->setState(InvalidationInterface::PROCESSING);
        $paths[] = $invalidation;
      }
      elseif ($invalidation->getType() == 'tag') {
        $invalidation->setState(InvalidationInterface::PROCESSING);
        $tags[] = $invalidation;
      }
      else {
        $invalidation->setState(InvalidationInterface::NOT_SUPPORTED);
      }
    }

    $processed = [
      'everything' => $everything,
      'paths' => $paths,
      'tags' => $tags,
    ];

    return $processed;
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
      'everything',
      'path',
      'tag',
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
