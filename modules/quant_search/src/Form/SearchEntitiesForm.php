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
        '#description' => $this->t('Optionally, restrict to these languages. If none are selected, all languages will be included.'),
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
    foreach (\Drupal::service('entity_display.repository')->getViewModes('node') as $key => $view_mode) {
      $node_view_modes[$key] = $view_mode['label'];
    }

    $form['node_details']['quant_search_entity_node_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled bundles'),
      '#description' => $this->t('Optionally, restrict to these content types. If none are selected, all content types will be included.'),
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Get all node type ids.
    $node_type_ids = ['default'];
    $node_types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();
    foreach ($node_types as $node_type) {
      $node_type_ids[] = $node_type->id();
    }

    // Get all tokens.
    $tokens = [];
    foreach ($node_type_ids as $node_type_id) {
      $typeTokens = $form_state->getValue('search_tokens_node_' . $node_type_id);
      // Only include if overriding the defaults.
      if ($node_type_id != 'default' && $typeTokens['enabled']) {
        $tokens[] = $typeTokens['quant_search_title_token'];
        $tokens[] = $typeTokens['quant_search_summary_token'];
        $tokens[] = $typeTokens['quant_search_image_token'];
      }
    }

    // Token module will find some of the invalid ones.
    $invalid_tokens = \Drupal::token()->getInvalidTokensByContext(implode(' ', $tokens), ['all']);

    // Also need to check for missing colon because token module does not.
    foreach ($tokens as $token) {
      // Multiple tokens might be present with text before, between, or after.
      preg_match_all('/
        ([^\\s\\[\\]:]*)  # match before the token
        \\[             # [ - pattern start
        ([^\\s\\[\\]:]*)  # match $type not containing whitespace : [ or ]
        :*              # : - separator
        ([^\\[\\]]*)     # match $name not containing [ or ]
        \\]             # ] - pattern end
        ([^\\s\\[\\]:]*)  # match after the token
        /x', $token, $matches);
      foreach ([$matches[1], $matches[2]] as $value) {
        if (empty($value)) {
          \Drupal::logger('kptesting')->error("invalid token: " . print_r($token, TRUE));
          $invalid_tokens[] = $token;
        }
      }
    }

    // Set one error if there are any invalid tokens.
    if (!empty($invalid_tokens)) {
      $form_state->setErrorByName('invalid-tokens', $this->t('You have one or more invalid tokens: @invalid_tokens', array('@invalid_tokens' => implode(', ', array_unique($invalid_tokens)))));
    }
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
