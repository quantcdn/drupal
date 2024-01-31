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
class SearchIndexForm extends ConfigFormBase {

  const SETTINGS = 'quant_search.index.settings';

  /**
   * The Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface
   */
  protected $client;

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
    // The `_custom_access` in routing ensures search is enabled for this page.
    $config = $this->config(self::SETTINGS);

    $form['quant_search_index_entity_node'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Nodes'),
      '#description' => $this->t('Reindex nodes.'),
    ];

    $form['node_details'] = [
      '#type' => 'details',
      '#tree' => FALSE,
      '#title' => $this->t('Node configuration'),
      '#states' => [
        'visible' => [
          ':input[name="quant_search_index_entity_node"]' => ['checked' => TRUE],
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

      $form['node_details']['quant_search_index_entity_node_languages'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Languages'),
        '#description' => $this->t('Optionally, restrict to these languages. If none are selected, all languages will be included.'),
        '#options' => $language_codes,
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

    $form['node_details']['quant_search_index_entity_node_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled bundles'),
      '#description' => $this->t('Optionally, restrict to these content types. If none are selected, all content types will be included.'),
      '#options' => $content_types,
    ];

    // @todo Implement taxonomy terms.
    $form['quant_search_index_entity_taxonomy_term'] = [
      '#type' => 'hidden',
      '#value' => FALSE,
      '#title' => $this->t('Taxonomy terms'),
      '#description' => $this->t('Reindex taxonomy term pages.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $process_nodes = $form_state->getValue('quant_search_index_entity_node');
    $process_terms = $form_state->getValue('quant_search_index_entity_taxonomy_term');

    if (empty($process_nodes) && empty($process_terms)) {
      $form_state->setErrorByName('quant_search_index_entity_node', $this->t('Choose an option to update the index.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $process_nodes = $form_state->getValue('quant_search_index_entity_node');
    $process_terms = $form_state->getValue('quant_search_index_entity_taxonomy_term');

    // @todo Handle taxonomy terms as well.
    if ($process_nodes) {
      $query = \Drupal::entityQuery('node')
        ->accessCheck(TRUE)
        ->condition('status', 1);

      $bundles = $form_state->getValue('quant_search_index_entity_node_bundles');

      if (!empty($bundles)) {
        $bundles = array_filter($bundles);
        if (!empty($bundles)) {
          $query->condition('type', array_keys($bundles), 'IN');
        }
      }

      $nids = $query->execute();

      // Chunk into a few batches.
      $batches = array_chunk($nids, 50);

      $batch = [
        'title' => $this->t('Exporting to Quant...'),
        'operations' => [],
        'init_message'     => $this->t('Starting'),
        'progress_message' => $this->t('Processed @current out of @total.'),
        'error_message'    => $this->t('An error occurred during processing.'),
      ];

      // Filter by language.
      $languages = $form_state->getValue('quant_search_index_entity_node_languages');

      foreach ($batches as $b) {
        $batch['operations'][] = ['quant_search_run_index', [$b, $languages]];
      }

      batch_set($batch);
    }

    parent::submitForm($form, $form_state);
  }

}
