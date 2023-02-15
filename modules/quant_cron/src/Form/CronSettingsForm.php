<?php

namespace Drupal\quant_cron\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant\QuantStaticTrait;

/**
 * Contains a form for configuring cron events.
 *
 * @internal
 */
class CronSettingsForm extends FormBase {

  use QuantStaticTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_cron_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $warnings = $this->getWarnings();
    $config = $this->config('quant_cron.settings');
    $moduleHandler = \Drupal::moduleHandler();

    if (!empty($warnings)) {
      $form['warnings'] = [
        '#type' => 'container',
        'title' => [
          '#markup' => '<strong>' . $this->t('Build warnings') . '</strong>',
        ],
        'list' => [
          '#theme' => 'item_list',
          '#items' => [],
        ],
      ];
      foreach ($warnings as $warning) {
        $form['warnings']['list']['#items'][] = [
          '#markup' => $warning,
        ];
      }
    }

    $form['entity_node'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Nodes'),
      '#description' => $this->t('Exports the latest revision of each node.'),
      '#default_value' => !empty($config->get('entity_node', '')),
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

      $form['entity_node_languages'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Languages'),
        '#description' => $this->t('Optionally restrict to these languages. If no options are selected all languages will be exported.'),
        '#options' => $language_codes,
        '#states' => [
          'visible' => [
            ':input[name="entity_node"]' => ['checked' => TRUE],
          ],
        ],
        '#default_value' => $config->get('entity_node_languages') ?? [],
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

    $form['entity_node_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled bundles'),
      '#description' => $this->t('Optionally restrict to these content types.'),
      '#options' => $content_types,
      '#states' => [
        'visible' => [
          ':input[name="entity_node"]' => ['checked' => TRUE],
        ],
      ],
      '#default_value' => $config->get('entity_node_bundles') ?? [],
    ];

    $form['entity_taxonomy_term'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Taxonomy terms'),
      '#description' => $this->t('Exports taxonomy term pages.'),
      '#default_value' => $config->get('entity_taxonomy_term'),
    ];

    $form['theme_assets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Theme assets'),
      '#description' => $this->t('Images, fonts and favicon in the public theme.'),
      '#default_value' => $config->get('theme_assets'),
    ];

    $form['views_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Views (Pages)'),
      '#description' => $this->t('Exports all views with a Page display accessible to anonymous users.'),
      '#default_value' => $config->get('views_pages'),
    ];

    $form['routes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Custom routes'),
      '#description' => $this->t('Exports custom list of routes. May be content or files.'),
      '#default_value' => $config->get('routes'),
    ];

    $form['routes_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Routes'),
      '#description' => $this->t('Add routes to export, each on a new line.'),
      '#states' => [
        'visible' => [
          ':input[name="routes"]' => ['checked' => TRUE],
        ],
      ],
      '#default_value' => $config->get('routes_export'),
    ];

    $form['file_paths'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('File paths'),
      '#description' => $this->t('Exports files with support for wildcards.'),
      '#default_value' => $config->get('file_paths'),
    ];

    $form['file_paths_textarea'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Local files'),
      '#description' => $this->t('Add paths to local files on disk. Must be relative to the Drupal webroot. Wildcards are accepted.'),
      '#states' => [
        'visible' => [
          ':input[name="file_paths"]' => ['checked' => TRUE],
        ],
      ],
      '#default_value' => $config->get('file_paths_textarea'),
    ];

    $form['robots'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Robots.txt'),
      '#description' => $this->t('Export robots.txt to Quant.'),
      '#default_value' => $config->get('robots'),
    ];

    if ($moduleHandler->moduleExists('lunr')) {
      $form['lunr'] = [
        '#type' => 'checkbox',
        '#title' => 'Lunr search assets',
        '#description' => $this->t('Exports required lunr javascript libraries and all search indexes for decoupled search.'),
        '#default_value' => $config->get('lunr'),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save cron settings'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('quant_cron.settings');
    $config->set('routes', $form_state->getValue('routes'))->save();
    $config->set('routes_export', $form_state->getValue('routes_textarea'))->save();
    $config->set('file_paths', $form_state->getValue('file_paths'))->save();
    $config->set('file_paths_textarea', $form_state->getValue('file_paths_textarea'))->save();
    $config->set('entity_node', $form_state->getValue('entity_node'))->save();
    $config->set('entity_node_languages', $form_state->getValue('entity_node_languages'))->save();
    $config->set('entity_node_bundles', $form_state->getValue('entity_node_bundles'))->save();
    $config->set('entity_taxonomy_term', $form_state->getValue('entity_taxonomy_term'))->save();
    $config->set('theme_assets', $form_state->getValue('theme_assets'))->save();
    $config->set('views_pages', $form_state->getValue('views_pages'))->save();
    $config->set('robots', $form_state->getValue('robots'))->save();
    $config->set('lunr', $form_state->getValue('lunr'))->save();
  }

}
