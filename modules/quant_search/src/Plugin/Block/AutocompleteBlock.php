<?php

namespace Drupal\quant_search\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides an 'AutocompleteBlock' block.
 *
 * @Block(
 *  id = "quant_autocomplete_block",
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

    $enabledPages = [];

    $storage = \Drupal::entityTypeManager()->getStorage('quant_search_page');
    $ids = \Drupal::entityQuery('quant_search_page')->execute();
    $pages = $storage->loadMultiple($ids);

    foreach ($pages as $page) {

      // Only process enabled pages.
      if (!$page->get('status')) {
        continue;
      }

      $enabledPages[$page->get('uuid')] = $page->get('label');
    }

    // Handle when no search pages are available.
    if (empty($enabledPages)) {
      $form['page'] = [
        '#type' => 'markup',
        '#markup' => '<h2>Error</h2><p><strong>You need at least one search page before adding a search block.</strong></p>',
        '#weight' => '0',
      ];
      return $form;
    }

    $form['page'] = [
      '#type' => 'select',
      '#title' => $this->t('Related search page'),
      '#description' => $this->t('Relevant search page for autocomplete. The content filters from this page will be used.'),
      '#default_value' => $this->configuration['page'],
      '#options' => $enabledPages,
      '#weight' => '0',
    ];

    $form['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder'),
      '#default_value' => $this->configuration['placeholder'] ?? 'Search',
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

    // Handle case if page was deleted or disabled.
    if (empty($page) || !$page->get('status')) {
      \Drupal::logger('quant_search')->error('Quant search block is missing its corresponding search page.');
      return [];
    }

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
            'placeholder' => $this->configuration['placeholder'] ?? 'Search',
            'show_summary' => $this->configuration['show_summary'] ?? FALSE,
            'search_path' => $route,
            'filters' => $filtersString,
          ],
        ],
      ],
    ];

  }

}
