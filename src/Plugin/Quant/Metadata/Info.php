<?php

namespace Drupal\quant\Plugin\Quant\Metadata;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\quant\Plugin\MetadataBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handle the general info metadata.
 *
 * @Metadata(
 *  id = "info",
 *  label = @Translation("Info")
 * )
 */
class Info extends MetadataBase implements ContainerFactoryPluginInterface {

  /**
   * The configuration factory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(EntityInterface $entity) : array {
    $meta = ['info' => []];

    // Retrieve basic metadata (author, date, revision log).
    $author = $entity->getRevisionUser();
    $date = $entity->getRevisionCreationTime();
    $log = $entity->getRevisionLogMessage();

    $meta['info']['author'] = $author->get('name')->value;
    $meta['info']['date_timestamp'] = $date;
    $meta['info']['log'] = $log;

    return $meta;
  }

}
