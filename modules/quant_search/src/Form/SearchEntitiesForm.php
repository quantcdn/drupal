<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class SearchEntitiesForm extends ConfigFormBase {

  const SETTINGS = 'quant_search.entities.settings';

  /**
   * Build the form.
   */
  public function __construct(QuantClientInterface $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quant_api.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_search.entities';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [
      self::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::SETTINGS);

    if ($config->get('api_token')) {
      if ($project = $this->client->project()) {
        if ($project->config->search_enabled) {
          $message = t('Search is enabled for @api', ['@api' => $config->get('api_project')]);
          \Drupal::messenger()->addMessage($message);
        }
        else {
          \Drupal::messenger()->addError(t('Search is not enabled for this project. Enable via the Quant Dashboard.'));
        }
      }
      else {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
      }
    }

    $form['quant_search_records_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Push search records'),
      '#description' => $this->t('Provide search record data when content is pushed.'),
      '#default_value' => $config->get('quant_search_records_enabled', TRUE),
    ];


    $form['quant_search_entity_node'] = [
      '#type' => 'checkbox',
      '#default_value' => $config->get('quant_search_entity_node'),
      '#title' => $this->t('Nodes'),
      '#description' => $this->t('Push node search records.'),
    ];

    $form['node_details'] = [
      '#type' => 'details',
      '#tree' => FALSE,
      '#title' => $this->t('Node configuration'),
      '#states' => [
        'visible' => [
          ':input[name="entity_node"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Seed by language.
    // Only active if there are more than one active languages.
    $languages = \Drupal::languageManager()->getLanguages();

    if (count($languages) > 1) {
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $language_codes = [];

      foreach ($languages as $langcode => $language) {
        $default = ($defaultLanguage->getId() == $langcode) ? ' (Default)' : '';
        $language_codes[$langcode] = $language->getName() . $default;
      }

      $form['node_details']['quant_search_entity_node_languages'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Languages'),
        '#description' => $this->t('Optionally restrict to these languages. If no options are selected all languages will be exported.'),
        '#options' => $language_codes,
        '#default_value' => $config->get('quant_search_entity_node_languages') ?: [],
      ];
    }

    // Seed by bundle.
    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $content_types = [];
    foreach ($types as $type) {
      $content_types[$type->id()] = $type->label();
    }

    $form['node_details']['quant_search_entity_node_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled bundles'),
      '#description' => $this->t('Optionally restrict to these content types.'),
      '#options' => $content_types,
      '#default_value' => $config->get('quant_search_entity_node_bundles') ?: [],
    ];

    $form['quant_search_entity_taxonomy_term'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Taxonomy terms'),
      '#description' => $this->t('Exports taxonomy term pages.'),
      '#default_value' => $config->get('quant_search_entity_taxonomy_term'),
    ];

    $form['search_tokens'] = [
      '#type' => 'vertical_tabs',
    ];

    $form['search_tokens_node'] = [
      '#type' => 'details',
      '#title' => 'Node',
      '#description' => 'Tokens related to nodes',
      '#group' => 'search_tokens',
      '#tree' => TRUE,
    ];

    $form['search_tokens_node']['quant_search_title_token'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#description' => 'Title',
      '#default_value' => $config->get('quant_search_title_token'),
    ];

    $form['search_tokens_node']['quant_search_summary_token'] = [
      '#type' => 'textfield',
      '#title' => 'Summary',
      '#description' => 'Summary',
      '#default_value' => $config->get('quant_search_summary_token'),
    ];

    $form['search_tokens_node']['quant_search_image_token'] = [
      '#type' => 'textfield',
      '#title' => 'Image',
      '#description' => 'Image',
      '#default_value' => $config->get('quant_search_image_token'),
    ];

    $form['search_tokens_node']['quant_search_content_viewmode'] = [
      '#type' => 'textfield',
      '#title' => 'Content view mode',
      '#description' => 'View mode to render the content as for search body',
      '#default_value' => $config->get('quant_search_content_viewmode'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $nodeTokens = $form_state->getValue('search_tokens_node');

    // Retrieve the configuration.
    $this->configFactory->getEditable(self::SETTINGS)
      ->set('quant_search_title_token', $nodeTokens['quant_search_title_token'])
      ->set('quant_search_entity_node', $form_state->getValue('quant_search_entity_node'))
      ->set('quant_search_entity_node_languages', $form_state->getValue('quant_search_entity_node_languages'))
      ->set('quant_search_entity_node_bundles', $form_state->getValue('quant_search_entity_node_bundles'))
      ->set('quant_search_entity_taxonomy_term', $form_state->getValue('quant_search_entity_taxonomy_term'))
      ->set('quant_search_summary_token', $nodeTokens['quant_search_summary_token'])
      ->set('quant_search_image_token', $nodeTokens['quant_search_image_token'])
      ->set('quant_search_content_viewmode', $nodeTokens['quant_search_content_viewmode'])
      ->save();


    // @todo: If "index" button is pressed..
    $query = \Drupal::entityQuery('node')
      ->condition('status', 1);

    $bundles = $form_state->getValue('quant_search_entity_node_bundles');

    if (!empty($bundles)) {
      $bundles = array_filter($bundles);
      if (!empty($bundles)) {
        $query->condition('type', array_keys($bundles), 'IN');
      }
    }

    $nids = $query->execute();

    // Chunk into a few batches.
    $batches = array_chunk($nids, 10);

    $batch = [
      'title' => $this->t('Exporting to Quant...'),
      'operations' => [],
      'init_message'     => $this->t('Commencing'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message'    => $this->t('An error occurred during processing'),
    ];

    foreach ($batches as $b) {
      $batch['operations'][] = ['quant_search_run_index', [$b]];
    }

    batch_set($batch);

    parent::submitForm($form, $form_state);
  }

}
