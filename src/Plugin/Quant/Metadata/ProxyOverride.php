<?php

namespace Drupal\quant\Plugin\Quant\Metadata;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\quant\Plugin\MetadataBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handle the published metadata.
 *
 * @Metadata(
 *  id = "proxy_override",
 *  label = @Translation("Proxy override"),
 *  description = @Translation("")
 * )
 */
class ProxyOverride extends MetadataBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(EntityInterface $entity) : array {
    // Proxies are created manually and usually not something you want to
    // replace. This is a globally configurable to allow override just in case.
    $config = \Drupal::config('quant.settings');
    $proxy_override = boolval($config->get('proxy_override', TRUE));
    return ['proxy_override' => $proxy_override];
  }

}
