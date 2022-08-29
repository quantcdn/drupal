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
    // The `_custom_access` in routing ensures search is enabled for this page.
    $config = $this->config(self::SETTINGS);

    $form['quant_search_entity_node'] = [
      '#type' => 'checkbox',
      '#default_value' => $config->get('quant_search_entity_node'),
      '#title' => $this->t('Nodes'),
      '#description' => $this->t('Keep search records for nodes updated.'),
    ];

    $form['node_details'] = [
      '#type' => 'details',
      '#tree' => FALSE,
      '#title' => $this->t('Node configuration'),
      '#states' => [
        'visible' => [
          ':input[name="quant_search_entity_node"]' => ['checked' => TRUE],
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
        '#description' => $this->t('Optionally restrict to these languages. If no options are selected all languages will have a search record created.'),
        '#options' => $language_codes,
        '#default_value' => $config->get('quant_search_entity_node_languages') ?: [],
      ];
    }

    // Get node bundles.
    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $content_types = [];
    foreach ($types as $type) {
      $content_types[$type->id()] = $type->label();
    }

    $node_view_modes = [];
    foreach (\Drupal::service('entity_display.repository')->getViewModes('node') as $k => $vm) {
      $node_view_modes[$k] = $vm['label'];
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
      '#description' => $this->t('Keep search records for taxonomy terms updated.'),
      '#default_value' => $config->get('quant_search_entity_taxonomy_term'),
    ];

    $form['search_tokens_node'] = [
      '#type' => 'vertical_tabs',
      '#states' => [
        'visible' => [
          ':input[name="quant_search_entity_node"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['search_tokens_node_default'] = [
      '#type' => 'details',
      '#title' => 'Default',
      '#group' => 'search_tokens_node',
      '#tree' => TRUE,
    ];

    $form['search_tokens_node_default']['quant_search_title_token'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#description' => 'Token to use for record title',
      '#default_value' => $config->get('quant_search_title_token'),
    ];

    $form['search_tokens_node_default']['quant_search_summary_token'] = [
      '#type' => 'textfield',
      '#title' => 'Summary',
      '#description' => 'Token to use for record summary',
      '#default_value' => $config->get('quant_search_summary_token'),
    ];

    $form['search_tokens_node_default']['quant_search_image_token'] = [
      '#type' => 'textfield',
      '#title' => 'Image',
      '#description' => 'Token to use for record image',
      '#default_value' => $config->get('quant_search_image_token'),
    ];

    $form['search_tokens_node_default']['quant_search_content_viewmode'] = [
      '#type' => 'select',
      '#title' => 'Content view mode',
      '#description' => 'View mode to render the content as for search body. Not used in display by default (search only value).',
      '#default_value' => $config->get('quant_search_content_viewmode'),
      '#options' => $node_view_modes,
    ];

    foreach ($types as $type) {

      $tokenConfig = $this->config(self::SETTINGS . '.' . $type->id());

      $form['search_tokens_node_' . $type->id()] = [
        '#type' => 'details',
        '#title' => $type->label(),
        '#group' => 'search_tokens_node',
        '#tree' => TRUE,
      ];

      $form['search_tokens_node_' . $type->id()]['exclude'] = [
        '#type' => 'checkbox',
        '#default_value' => $tokenConfig->get('exclude'),
        '#title' => $this->t('Exclude'),
        '#description' => $this->t('Excludes this type from the search index.'),
      ];

      $form['search_tokens_node_' . $type->id()]['enabled'] = [
        '#type' => 'checkbox',
        '#default_value' => $tokenConfig->get('enabled'),
        '#title' => $this->t('Override'),
        '#description' => $this->t('Override default node values.'),
      ];

      $form['search_tokens_node_' . $type->id()]['quant_search_title_token'] = [
        '#type' => 'textfield',
        '#title' => 'Title',
        '#default_value' => $tokenConfig->get('quant_search_title_token'),
        '#states' => [
          'enabled' => [
            ':input[name="search_tokens_node_' . $type->id() . '[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['search_tokens_node_' . $type->id()]['quant_search_summary_token'] = [
        '#type' => 'textfield',
        '#title' => 'Summary',
        '#default_value' => $tokenConfig->get('quant_search_summary_token'),
        '#states' => [
          'enabled' => [
            ':input[name="search_tokens_node_' . $type->id() . '[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['search_tokens_node_' . $type->id()]['quant_search_image_token'] = [
        '#type' => 'textfield',
        '#title' => 'Image',
        '#default_value' => $tokenConfig->get('quant_search_image_token'),
        '#states' => [
          'enabled' => [
            ':input[name="search_tokens_node_' . $type->id() . '[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['search_tokens_node_' . $type->id()]['quant_search_content_viewmode'] = [
        '#type' => 'select',
        '#title' => 'Content view mode',
        '#description' => 'View mode to render the content as for search body',
        '#default_value' => $tokenConfig->get('quant_search_content_viewmode'),
        '#options' => $node_view_modes,
        '#states' => [
          'enabled' => [
            ':input[name="search_tokens_node_' . $type->id() . '[enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $nodeTokens = $form_state->getValue('search_tokens_node_default');

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

    // Iterate node type overrides.
    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $content_types = [];
    foreach ($types as $type) {
      $typeTokens = $form_state->getValue('search_tokens_node_' . $type->id());

      $typeConfig = $this->configFactory->getEditable(self::SETTINGS . '.' . $type->id())
        ->set('exclude', $typeTokens['exclude'])
        ->set('enabled', $typeTokens['enabled'])
        ->set('quant_search_title_token', $typeTokens['quant_search_title_token'])
        ->set('quant_search_summary_token', $typeTokens['quant_search_summary_token'])
        ->set('quant_search_image_token', $typeTokens['quant_search_image_token'])
        ->set('quant_search_content_viewmode', $typeTokens['quant_search_content_viewmode'])
        ->save();
    }

    parent::submitForm($form, $form_state);
  }

}
