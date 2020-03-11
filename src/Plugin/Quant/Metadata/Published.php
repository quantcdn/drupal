<?php

namespace Drupal\quant\Plugin\Quant\Metadata;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\quant\Annotation\Metadata;
use Drupal\quant\Plugin\MetadataBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handle the published metadata.
 *
 * @Metadata(
 *  id = "published",
 *  label = @Translation("Published")
 * )
 */
class Published extends MetadataBase implements ContainerFactoryPluginInterface {

  /**
   * @var EntityStorage
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
    // Note: This approach returns the default published state of the parent node.
    // This should be used to make content non-viewable if it is unpublished.
    //$default = $this->entityManager->getStorage($entity->getEntityTypeId())->load($entity->id());

    // Return the published status of the revision.
    return ['published' => $entity->isPublished()];
  }

}
