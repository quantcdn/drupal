<?php

namespace Drupal\quant\Plugin\Quant\Metadata;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\quant\Plugin\MetadataBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;

/**
 * Handle the general info metadata.
 *
 * @Metadata(
 *  id = "info",
 *  label = @Translation("Info"),
 *  description = @Translation("Metadata information about the exported entity")
 * )
 */
class Info extends MetadataBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The configuration factory object.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The token service.
   *
   * @var Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, Token $token) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
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
      $container->get('config.factory'),
      $container->get('token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'author' => '[node:author:name]',
      'date' => '[node:created]',
      'log' => '[node:revision_log]',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm() {
    $form['author'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Author'),
      '#description' => $this->t('A string to use for the author name, can use node tokens.'),
      '#default_value' => $this->getConfig('author'),
    ];

    $form['date'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Date'),
      '#description' => $this->t('A string to use for the date, can use node tokens'),
      '#default_value' => $this->getConfig('date'),
    ];

    $form['log'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Log'),
      '#description' => $this->t('A string to use for the revision log, can use node tokens'),
      '#default_value' => $this->getConfig('log'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build(EntityInterface $entity) : array {
    $meta = ['info' => []];

    $ctx[$entity->getEntityTypeId()] = $entity;
    $log = $entity->getRevisionLogMessage();

    if (!empty($this->getConfig('author'))) {
      $meta['info']['author'] = $this->token->replace($this->getConfig('author'), $ctx);
    }

    if (!empty($this->getConfig('date'))) {
      $meta['info']['date_timestamp'] = $this->token->replace($this->getConfig('date'), $ctx);
    }

    $meta['info']['log'] = $log;

    return $meta;
  }

}
