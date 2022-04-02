<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\quant_search\Controller\Search;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\Event\QuantEvent;

/**
 * Form handler for the Quant Search page add and edit forms.
 */
class QuantSearchPageForm extends EntityForm {

  /**
   * Constructs an QuantSearchPageForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entityTypeManager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $page = $this->entity;

    $form['#tree'] = TRUE;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $page->label(),
      '#description' => $this->t("Administrative label for the search page."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $page->id(),
      '#machine_name' => [
        'exists' => [$this, 'exist'],
      ],
      '#disabled' => !$page->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('title'),
      '#description' => $this->t("Page title."),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->get('description'),
      '#description' => $this->t('Search page description.'),
    ];

    $languages = \Drupal::languageManager()->getLanguages();

    if (count($languages) > 1) {
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $language_codes = [];

      foreach ($languages as $langcode => $language) {
        $default = ($defaultLanguage->getId() == $langcode) ? ' (Default)' : '';
        $language_codes[$langcode] = $language->getName() . $default;
      }

      $form['languages'] = [
        '#type' => 'select',
        '#title' => $this->t('Languages'),
        '#description' => $this->t('Optionally restrict to these languages. If no options are selected all languages will be included.'),
        '#options' => $language_codes,
        '#default_value' => $this->entity->get('languages'),
        '#multiple' => TRUE,
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

    $form['bundles'] = [
      '#type' => 'select',
      '#title' => $this->t('Enabled bundles'),
      '#description' => $this->t('Optionally restrict to these content types.'),
      '#options' => $content_types,
      '#default_value' => $this->entity->get('bundles'),
      '#multiple' => TRUE,
    ];

    $form['manual_filters'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Manual Filters'),
      '#maxlength' => 2048,
      '#default_value' => $this->entity->get('manual_filters'),
      '#description' => $this->t('Optionally provide complex filters. For example: <code>cost>10 AND cost<99.5</code>. You may join with ANDs, ORs.'),
    ];

    $form['route'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Route'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('route'),
      '#description' => $this->t('Page route for the search page.'),
      '#required' => TRUE,
    ];

    $existingDisplayConfig = $this->entity->get('display');

    // Form state facets trump the entity state.
    $vals = $form_state->getValues();
    if (!empty($vals['display'])) {
      $existingDisplayConfig = $vals['display'];
    }

    $form['display'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Display options'),
      '#prefix' => '<div id="display-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    $form['display']['results'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Results display'),
    ];

    $form['display']['results']['display_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display search textfield'),
      '#default_value' => $existingDisplayConfig['results']['display_search'] ?? TRUE,
    ];

    $form['display']['results']['display_stats'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display stats'),
      '#description' => $this->t('e.g number of hits/results for a query'),
      '#default_value' => $existingDisplayConfig['results']['display_stats'] ?? TRUE,
    ];

    $form['display']['results']['show_clear_refinements'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display clear refinements button'),
      '#default_value' => $existingDisplayConfig['results']['show_clear_refinements'] ?? TRUE,
    ];

    $form['display']['pagination'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Pagination options'),
    ];

    $form['display']['pagination']['pagination_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pagination enabled'),
      '#default_value' => $existingDisplayConfig['pagination']['pagination_enabled'] ?? TRUE,
    ];

    $form['display']['pagination']['per_page'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hits per page'),
      '#default_value' => $existingDisplayConfig['pagination']['per_page'] ?? 20,
    ];

    $form['facets'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Facets'),
      '#prefix' => '<div id="facets-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    // Retrieve facets attached to the entity.
    $existingFacets = $this->entity->get('facets');
    $existingDisplayConfig = $this->entity->get('display');

    // Form state facets trump the entity state.
    $vals = $form_state->getValues();
    if (!empty($vals['facets'])) {
      $existingFacets = $vals['facets'];
    }
    else {
      $existingFacets[] = [];
    }

    foreach ($existingFacets as $i => $facet) {

      $form['facets'][$i] = [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t('Facet configuration'),
      ];

      $types = [
        'taxonomy' => 'Taxonomy',
        'content_type' => 'Content type',
        'language' => 'Language',
        'custom' => 'Custom',
      ];

      $displayTypes = [
        'checkbox' => "Checkbox (multi select)",
        'select' => "Select list (single select)",
        'menu' => "Menu list (single select)",
      ];

      $form['facets'][$i]['facet_display'] = [
        '#type' => 'select',
        '#title' => $this->t('Facet type'),
        '#options' => $displayTypes,
        '#default_value' => $facet['facet_display'],
        '#attributes' => [
          'id' => "facet_{$i}_display_type",
        ],
      ];

      $form['facets'][$i]['facet_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Facet type'),
        '#options' => $types,
        '#default_value' => $facet['facet_type'],
        '#attributes' => [
          'id' => "facet_{$i}_type",
        ],
      ];

      $vocabularies = Vocabulary::loadMultiple();

      $vocab_options = [];
      foreach ($vocabularies as $vocab) {
        $vocab_options[$vocab->id()] = $vocab->label();
      }

      $form['facets'][$i]['taxonomy_vocabulary'] = [
        '#type' => 'select',
        '#title' => t('Taxonomy vocabulary'),
        '#options' => $vocab_options,
        '#default_value' => $facet['taxonomy_vocabulary'],
        '#states' => [
          'visible' => [
            ':input[id="facet_' . $i . '_type"]' => ['value' => 'taxonomy'],
          ],
        ],
      ];

      $form['facets'][$i]['custom_key'] = [
        '#type' => 'textfield',
        '#title' => t('Custom key'),
        '#description' => t('Provide a custom key as defined in your entity token configuration'),
        '#default_value' => $facet['custom_key'],
        '#states' => [
          'visible' => [
            ':input[id="facet_' . $i . '_type"]' => ['value' => 'custom'],
          ],
        ],
      ];

      $form['facets'][$i]['facet_heading'] = [
        '#type' => 'textfield',
        '#title' => t('Facet heading'),
        '#default_value' => $facet['facet_heading'],
      ];

      $languages = \Drupal::languageManager()->getLanguages();
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $language_codes = [];

      foreach ($languages as $langcode => $language) {
        $default = ($defaultLanguage->getId() == $langcode) ? ' (Default)' : '';
        $language_codes[$langcode] = $language->getName() . $default;
      }

      $form['facets'][$i]['facet_language'] = [
        '#type' => 'select',
        '#title' => $this->t('Facet language'),
        '#description' => $this->t('Language to use for the facet.'),
        '#options' => $language_codes,
        '#default_value' => $facet['facet_language'],
      ];

      $form['facets'][$i]['actions']['remove_facet'] = [
        '#type' => 'submit',
        '#value' => t('Remove facet'),
        '#name' => 'remove_facet_' . $i,
        '#index' => $i, 
        '#submit' => ['::removeCallback'],
        '#ajax' => [
          'callback' => '::addmoreCallback',
          'wrapper' => 'facets-fieldset-wrapper',
        ],
      ];
    }

    // Add "add facet" button to last item in the array.
    $form['facets'][$i]['actions']['add_facet'] = [
      '#type' => 'submit',
      '#value' => t('Add facet'),
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addmoreCallback',
        'wrapper' => 'facets-fieldset-wrapper',
      ],
    ];

    $form_state->setCached(FALSE);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    unset($form['facets']['actions']);
    $page = $this->entity;
    $status = $page->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addMessage($this->t('The %label search page created.', [
        '%label' => $page->label(),
      ]));
    }
    else {
      $this->messenger()->addMessage($this->t('The %label search page updated.', [
        '%label' => $page->label(),
      ]));
    }

    // Ensure API is aware of facets and enable as required.
    $facets = $form_state->getValue(['facets']);
    unset($facets['actions']);

    $keys = Search::processTranslatedFacetKeys($facets);

    $uniqKeys = [];
    foreach ($keys as $k) {
      if (!isset($uniqKeys[$k['facet_key']])) {
        $uniqKeys[] = $k['facet_key'];
      }
    }

    if (!empty($keys)) {
      $client = \Drupal::service('quant_api.client');
      $client->addFacets($uniqKeys);
    }

    \Drupal::service('router.builder')->rebuild();

    // Seed the route in Quant.
    $published = $form_state->getValue('status');
    $route = $form_state->getValue('route');

    if ($published) {
      $item = new RouteItem(['route' => $route]);
      $item->send();
    }
    else {
      \Drupal::service('event_dispatcher')->dispatch(QuantEvent::UNPUBLISH, new QuantEvent('', $route, [], NULL));
    }

    $form_state->setRedirect('entity.quant_search_page.collection');
  }

  /**
   * Helper function to check whether an search page configuration entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('quant_search_page')->getQuery()
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }

  /**
   *
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $vals = $form_state->getValues();
    $vals['facets'][] = [];
    $form_state->setValues($vals);
    $form_state->setRebuild();
  }

  /**
   *
   */
  public function addmoreCallback(array &$form, FormStateInterface $form_state) {
    return $form['facets'];
  }

  /**
   *
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $element = $form_state->getTriggeringElement();

    // Remove based on item index.
    if (!isset($element['#index'])) {
      return;
    }

    $idx = $element['#index'];
    if (isset($values['facets'][$idx])) {
      unset($values['facets'][$idx]);
    }

    // Facets are empty, add a new blank item.
    if (empty($values['facets'])) {
      $values['facets'][] = [];
    }

    $form_state->setValues($values);
    $form_state->setRebuild();
    return;
  }

}
