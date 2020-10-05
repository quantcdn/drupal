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
      'author' => '[user:name]',
      'include_revision_log' => TRUE,
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

    $form['include_revision_log'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include revision log'),
      '#description' => $this->t('Include revision log message in metadata'),
      '#default_value' => $this->getConfig('include_revision_log'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function build(EntityInterface $entity) : array {
    $meta = ['info' => []];

    $author = $entity->getRevisionUser();
    $ctx[$entity->getEntityTypeId()] = $entity;
    $date = $entity->getRevisionCreationTime();

    // Provide an author context.
    $ctx['user'] = $author;

    $log = $entity->getRevisionLogMessage();

    if (!empty($this->getConfig('author'))) {
      $meta['info']['author'] = $this->token->replace($this->getConfig('author'), $ctx);
    }

    $meta['content_timestamp'] = $date;

    if ($this->getConfig('include_revision_log')) {
      $meta['info']['log'] = $entity->getRevisionLogMessage();
    }

    return $meta;
  }

}
