<?php

namespace Drupal\quant\Plugin\Quant\Metadata;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\quant\Plugin\MetadataBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Handle the transitions metadata.
 *
 * @Metadata(
 *  id = "transitions",
 *  label = @Translation("Transitions"),
 *  description = @Translation("")
 * )
 */
class Transitions extends MetadataBase implements ContainerFactoryPluginInterface {

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
    $timezone = $this->configFactory->get('system.date')->get('timezone.default');
    $meta = ['transitions' => []];

    try {
      $date = $entity->get('scheduled_transition_date')->getValue();
      $state = $entity->get('scheduled_transition_state')->getValue();
    }
    catch (\Exception $error) {
      return $meta;
    }

    if (empty($date) || empty($state)) {
      return $meta;
    }

    foreach ($state as $delta => $d) {
      $dt = new \DateTime($date[$delta]['value'], new \DateTimeZone('UTC'));
      $dt->setTimeZone(new \DateTimeZone($timezone));
      $meta['transitions'][] = [
        'date_timestamp' => $dt->getTimestamp(),
        'state' => $state[$delta]['value'],
      ];
    }

    return $meta;
  }

}
