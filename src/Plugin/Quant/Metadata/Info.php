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
      'author_name' => '[user:name]',
      'include_revision_log' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm() {
    $form['author_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Author'),
      '#description' => $this->t('A string to use for the author name, can use node tokens.'),
      '#default_value' => $this->getConfig('author_name'),
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
  public function build(EntityInterface $entity, $langcode = NULL) : array {
    $meta = ['info' => []];

    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }
    else {
      $langcode = $entity->language()->getId();
    }

    $author = $entity->getRevisionUser();
    $ctx[$entity->getEntityTypeId()] = $entity;
    $date = $entity->getRevisionCreationTime();

    // Provide an author context.
    $ctx['user'] = $author;

    if (!empty($this->getConfig('author_name'))) {
      $meta['info']['author_name'] = $this->token->replace($this->getConfig('author_name'), $ctx, [
        'langcode' => $langcode,
        'clear' => TRUE,
      ]);
    }

    $meta['content_timestamp'] = intval($date);

    if ($this->getConfig('include_revision_log')) {
      $log = $entity->getRevisionLogMessage();

      if (!empty($log)) {
        $meta['info']['log'] = substr($log, 0, 255);
      }
    }

    // Add search meta for node entities.
    // @todo these will all need translating..
    if ($entity->getEntityTypeId() == 'node') {
      $meta['search_record']['categories'] = $this->getNodeTerms($entity);
      $meta['search_record']['categories']['content_type'] = $entity->type->entity->label();
    }

    $meta['search_record']['lang_code'] = $langcode;

    return $meta;
  }

  /**
   * Retrieves any terms attached to a given node.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to gather info for.
   *
   * @return array
   *   Terms tagged to the node.
   */
  public function getNodeTerms(EntityInterface $entity) {
    $query = \Drupal::database()
      ->select('taxonomy_index', 'ti')
      ->fields('ti', ['tid'])
      ->condition('nid', $entity->id());

    $results = $query->execute()->fetchCol();

    $tids = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($results);

    $terms = [];

    foreach ($tids as $term) {
      $terms[$term->bundle()][] = $term->label();
    }

    return $terms;
  }

}
