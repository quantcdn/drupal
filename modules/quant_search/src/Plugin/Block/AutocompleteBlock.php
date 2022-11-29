<?php

namespace Drupal\quant_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a 'AutocompleteBlock' block.
 *
 * @Block(
 *  id = "autocomplete_block",
 *  admin_label = @Translation("Quant Search Autocomplete"),
 * )
 */
class AutocompleteBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {

    $searchPages = [];

    $storage = \Drupal::entityTypeManager()->getStorage('quant_search_page');
    $ids = \Drupal::entityQuery('quant_search_page')->execute();
    $pages = $storage->loadMultiple($ids);

    foreach ($pages as $page) {

      // Only process enabled pages.
      if (!$page->get('status')) {
        continue;
      }

      $searchPages[$page->get('uuid')] = $page->get('label');

    }

    $form['page'] = [
      '#type' => 'select',
      '#title' => $this->t('Related search page'),
      '#description' => $this->t('Relevant search page for autocomplete, will inherit content filters.'),
      '#default_value' => $this->configuration['page'],
      '#options' => $searchPages,
      '#weight' => '0',
    ];

    $form['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder'),
      '#default_value' => $this->configuration['placeholder'] ?? 'Search..',
      '#weight' => '0',
    ];

    $form['show_summary'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include summary'),
      '#default_value' => $this->configuration['show_summary'] ?? FALSE,
      '#weight' => '0',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['page'] = $form_state->getValue('page');
    $this->configuration['placeholder'] = $form_state->getValue('placeholder');
    $this->configuration['show_summary'] = $form_state->getValue('show_summary');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $client = \Drupal::service('quant_api.client');
    $project = $client->project();
    $pageUuid = $this->configuration['page'];

    $page = \Drupal::service('entity.repository')->loadEntityByUuid('quant_search_page', $pageUuid);

    $languages = $page->get('languages');
    $bundles = $page->get('bundles');
    $manualFilters = $page->get('manual_filters');
    $route = $page->get('route');

    // Ensure route starts with a slash.
    if (substr($route, 0, 1) != '/') {
      $route = "/{$route}";
    }

    $filters = [];

    if (!empty($languages)) {
      $filters[] = "(lang_code:'" . implode('\' OR lang_code:\'', $languages) . "')";
    }

    if (!empty($bundles)) {
      $filters[] = "(content_type:'" . implode('\' OR content_type:\'', $bundles) . "')";
    }

    if (!empty($manualFilters)) {
      $filters[] = $manualFilters;
    }

    $filtersString = implode(' AND ', $filters);

    return [
      '#theme' => 'autocomplete_block',
      '#page' => $this->configuration['page'],
      '#cache' => [
        'tags' => $this->getCacheTags(),
      ],
      '#attached' => [
        'library' => [
          'quant_search/algolia-autocomplete',
          'quant_search/autocomplete-block',
        ],
        'drupalSettings' => [
          'quantSearchAutocomplete' => [
            'algolia_application_id' => $project->config->search_index->algolia_application_id,
            'algolia_read_key' => $project->config->search_index->algolia_read_key,
            'algolia_index' => $project->config->search_index->algolia_index,
            'placeholder' => $this->configuration['placeholder'] ?? 'Search..',
            'show_summary' => $this->configuration['show_summary'] ?? FALSE,
            'search_path' => $route,
            'filters' => $filtersString,
          ],
        ],
      ],
    ];

  }

}