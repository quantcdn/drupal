<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant_search\Controller\Search;
use Drupal\taxonomy\Entity\Vocabulary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Quant Search Page add and edit forms.
 */
class QuantSearchPageForm extends EntityForm {

  /**
   * The entity type manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a QuantSearchPageForm object.
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

    $form['#attached']['library'][] = 'quant_search/drupal.quant_search.admin';

    $page = $this->entity;

    $form['#tree'] = TRUE;

    // Search page status.
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    // Search page administrative label.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $page->label(),
      '#description' => $this->t('Administrative label for the search page.'),
      '#required' => TRUE,
    ];

    // Search page machine name.
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $page->id(),
      '#machine_name' => [
        'exists' => [$this, 'exist'],
      ],
      '#disabled' => !$page->isNew(),
    ];

    // Search page route.
    $form['route'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Route'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('route'),
      '#description' => $this->t('Page route for the search page without a starting slash.'),
      '#required' => TRUE,
    ];

    // Search page title shown on page.
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('title'),
      '#description' => $this->t('Page title shown on the search page.'),
    ];

    // Search page description shown on page.
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->get('description'),
      '#description' => $this->t('Description shown on the search page.'),
    ];

    // If there is more than one language, allow language restriction.
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
        '#description' => $this->t('Optionally, restrict search to these languages. If none are selected, all languages will be included.'),
        '#options' => $language_codes,
        '#default_value' => $this->entity->get('languages'),
        '#multiple' => TRUE,
      ];
    }

    // If there is more than one content type, allow content type restriction.
    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    if (count($types) > 1) {
      $content_types = [];
      foreach ($types as $type) {
        $content_types[$type->id()] = $type->label();
      }

      $form['bundles'] = [
        '#type' => 'select',
        '#title' => $this->t('Content types'),
        '#description' => $this->t('Optionally, restrict search to these content types. If none are selected, all content types will be included.'),
        '#options' => $content_types,
        '#default_value' => $this->entity->get('bundles'),
        '#multiple' => TRUE,
      ];
    }

    // Search page manual filters.
    $form['manual_filters'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Manual filters'),
      '#maxlength' => 2048,
      '#default_value' => $this->entity->get('manual_filters'),
      '#description' => $this->t('Optionally, provide manual filters that can include ANDs and ORs. For example: <code>cost > 10 AND cost < 99.5</code>'),
    ];

    // Get entity display configuration.
    $existingDisplayConfig = $this->entity->get('display');

    // If form state has values, use those instead of the entity state values.
    $vals = $form_state->getValues();
    if (!empty($vals['display'])) {
      $existingDisplayConfig = $vals['display'];
    }

    // Display options.
    $form['display'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Display options'),
      '#prefix' => '<div id="display-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    // Form display options.
    $form['display']['results'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Form display'),
    ];

    // Show the keyword field.
    $form['display']['results']['display_search'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display search keyword field'),
      '#default_value' => $existingDisplayConfig['results']['display_search'] ?? TRUE,
    ];

    // Show the number of results.
    $form['display']['results']['display_stats'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display number of results'),
      '#default_value' => $existingDisplayConfig['results']['display_stats'] ?? TRUE,
    ];

    // Show the "Clear refinements" button.
    $form['display']['results']['show_clear_refinements'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display "Clear refinements" button'),
      '#default_value' => $existingDisplayConfig['results']['show_clear_refinements'] ?? TRUE,
    ];

    // Pagination display options.
    $form['display']['pagination'] = [
      '#type' => 'details',
      '#open' => FALSE,
      '#title' => $this->t('Pagination display'),
    ];

    // Enable pagination.
    $form['display']['pagination']['pagination_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable pagination'),
      '#default_value' => $existingDisplayConfig['pagination']['pagination_enabled'] ?? TRUE,
    ];

    // Number of results per page.
    $form['display']['pagination']['per_page'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Results per page'),
      '#default_value' => $existingDisplayConfig['pagination']['per_page'] ?? 20,
    ];

    // Create tabledrag facets table.
    $form['facets'] = [
      '#type' => 'table',
      // Do not show the weight header as each select has a label.
      '#header' => [
        [
          'data' => $this->t('Facet configuration'),
          // IMPORTANT: Must be the correct value or tabledrag doesn't work!
          'colspan' => 6,
        ],
      ],
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'facet-sort-weight',
        ],
      ],
      '#prefix' => '<div id="facets-fieldset-wrapper">',
      '#suffix' => '</div>',
    ];

    // Get entity facets configuration.
    $existingFacets = $this->entity->get('facets');

    // If form state has values, use those instead of the entity state values.
    $vals = $form_state->getValues();
    if (!empty($vals['facets'])) {
      $existingFacets = $vals['facets'];
    }
    else {
      $existingFacets[] = [];
    }

    // Configuration fields for all the facets.
    foreach ($existingFacets as $i => $facet) {

      // Mark the table row as draggable.
      $form['facets'][$i]['#attributes']['class'][] = 'draggable';

      // Sort the table row according to its configured weight.
      $form['facets'][$i]['#weight'] = $facet['weight'] ?? 10;

      // Facet display.
      $displayTypes = [
        '' => 'Select facet display',
        'checkbox' => 'Checkbox (multi select)',
        'select' => 'Select list (single select)',
        'menu' => 'Menu list (single select)',
      ];

      // @todo Make required. When adding '#required', it didn't save.
      $form['facets'][$i]['facet_display'] = [
        '#type' => 'select',
        '#title' => $this->t('Facet display'),
        '#options' => $displayTypes,
        '#default_value' => $facet['facet_display'] ?? '',
        '#attributes' => [
          'id' => "facet_{$i}_display_type",
        ],
      ];

      // Facet type based on common usage patterns.
      $types = [
        '' => 'Select facet type',
        'taxonomy' => 'Taxonomy',
        'content_type' => 'Content type',
        'language' => 'Language',
        'custom' => 'Custom',
      ];

      // @todo Make required. When adding '#required', it didn't save.
      $form['facets'][$i]['facet_type_config']['facet_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Facet type'),
        '#options' => $types,
        '#default_value' => $facet['facet_type_config']['facet_type'] ?? '',
        '#attributes' => [
          'id' => "facet_{$i}_type",
        ],
      ];

      // For taxonomy option, store all vocabularies.
      $vocabularies = Vocabulary::loadMultiple();

      $vocab_options = [
        '' => $this->t('Select vocabulary'),
      ];
      foreach ($vocabularies as $vocab) {
        $vocab_options[$vocab->id()] = $vocab->label();
      }

      $form['facets'][$i]['facet_type_config']['taxonomy_vocabulary'] = [
        '#type' => 'select',
        '#title' => $this->t('Vocabulary'),
        '#options' => $vocab_options,
        '#default_value' => $facet['facet_type_config']['taxonomy_vocabulary'] ?? '',
        '#states' => [
          'visible' => [
            ':input[id="facet_' . $i . '_type"]' => ['value' => 'taxonomy'],
          ],
        ],
      ];

      // Allow custom option using defined entity.
      $form['facets'][$i]['facet_type_config']['custom_key'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Custom key'),
        '#size' => 20,
        '#description' => t('Entity token configuration key.'),
        '#default_value' => $facet['facet_type_config']['custom_key'] ?? '',
        '#states' => [
          'visible' => [
            ':input[id="facet_' . $i . '_type"]' => ['value' => 'custom'],
          ],
        ],
      ];

      // Facet heading to display.
      // @todo If only one language, default to default language.
      $form['facets'][$i]['facet_heading'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Facet heading'),
        '#size' => 20,
        '#default_value' => $facet['facet_heading'] ?? '',
      ];

      // Facet language.
      // @todo Only show if more than one language but beware of colspan.
      $defaultLanguage = \Drupal::languageManager()->getDefaultLanguage();
      $language_codes = [];

      foreach ($languages as $langcode => $language) {
        $default = ($defaultLanguage->getId() == $langcode) ? ' (Default)' : '';
        $language_codes[$langcode] = $language->getName() . $default;
      }

      $form['facets'][$i]['facet_language'] = [
        '#type' => 'select',
        '#title' => $this->t('Facet language'),
        '#options' => $language_codes,
        '#default_value' => $facet['facet_language'] ?? 'en',
      ];

      $form['facets'][$i]['facet_limit'] = [
        '#type' => 'number',
        '#title' => $this->t('Facet limit'),
        '#default_value' => $facet['facet_limit'] ?? 10,
      ];

      // Weight column element.
      $form['facets'][$i]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t('Facet weight'),
        '#default_value' => $facet['weight'] ?? 10,
        '#attributes' => [
          'class' => [
            'facet-sort-weight',
          ],
        ],
      ];

      // Remove facet button.
      $form['facets'][$i]['actions']['remove_facet'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove facet'),
        '#name' => 'remove_facet_' . $i,
        '#index' => $i,
        '#attributes' => [
          'class' => [
            'facet-remove',
          ],
        ],
        '#submit' => ['::removeCallback'],
        '#ajax' => [
          'callback' => '::addCallback',
          'wrapper' => 'facets-fieldset-wrapper',
        ],
      ];

    }

    // Add "Add facet" button to last item in the array.
    $form['facets'][$i]['actions']['add_facet'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add facet'),
      '#attributes' => [
        'class' => [
          'facet-add',
        ],
      ],
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addCallback',
        'wrapper' => 'facets-fieldset-wrapper',
      ],
    ];

    $form_state->setCached(FALSE);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Completely empty facets are allowed since they will be removed during the
    // form save, so we only need to validate when facet data is set.
    $facets = $form_state->getValue(['facets']);
    foreach ($facets as $facet) {
      // Check if facet display is set but not type.
      if ($facet['facet_display'] && empty($facet['facet_type_config']['facet_type'])) {
        $form_state->setErrorByName('missing-type', $this->t('Missing facet type.'));
      }

      // Check facet type configuration.
      if ($facet['facet_type_config']['facet_type']) {
        // Check for missing display.
        if (empty($facet['facet_display'])) {
          $form_state->setErrorByName('missing-display', $this->t('Missing facet display.'));
        }
        // Check for taxonomy facets without corresponding vocabulary.
        if ($facet['facet_type_config']['facet_type'] == "taxonomy" && empty($facet['facet_type_config']['taxonomy_vocabulary'])) {
          $form_state->setErrorByName('missing-vocabulary', $this->t('Missing taxonomy facet vocabulary.'));
        }
        // Check for custom facets without corresponding key.
        if ($facet['facet_type_config']['facet_type'] == "custom" && empty($facet['facet_type_config']['custom_key'])) {
          $form_state->setErrorByName('missing-custom-key', $this->t('Missing custom facet key.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    unset($form['facets']['actions']);
    $page = $this->entity;

    // Remove all empty facets, so they are not added to the page.
    $facets = $page->get('facets');
    $nonEmptyFacets = [];
    foreach ($facets as $i => $facet) {
      if ($facet['facet_display'] && $facet['facet_type_config']['facet_type']) {
        $nonEmptyFacets[$i] = $facet;
      }
    }
    $page->set('facets', $nonEmptyFacets);

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

    // Ensure the API is aware of the facets and enable them as required.
    $facets = $form_state->getValue(['facets']);
    unset($facets['actions']);

    $keys = Search::processTranslatedFacetKeys($facets);

    $uniqueKeys = [];

    // Add default filters (lang_code/bundle).
    $uniqueKeys[] = 'lang_code';
    $uniqueKeys[] = 'content_type';

    foreach ($keys as $k) {
      if (!isset($uniqueKeys[$k['facet_key']])) {
        $uniqueKeys[] = $k['facet_key'];
      }
    }

    if (!empty($keys)) {
      $client = \Drupal::service('quant_api.client');
      $client->addFacets($uniqueKeys);
    }

    // In case the route is new, rebuild the router, so it is added.
    \Drupal::service('router.builder')->rebuild();

    // Add or remove the route in Quant.
    $published = $form_state->getValue('status');
    $route = $form_state->getValue('route');

    // Send route for enabled pages.
    if ($published) {
      $item = new RouteItem(['route' => $route]);
      $item->send();
    }
    // Only unpublish if page already exists, so was sent before.
    elseif ($status !== SAVED_NEW) {
      \Drupal::service('event_dispatcher')->dispatch(new QuantEvent('', $route, [], NULL), QuantEvent::UNPUBLISH);
    }

    $form_state->setRedirect('entity.quant_search_page.collection');
  }

  /**
   * Helper function to check if a search page configuration entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('quant_search_page')->getQuery()
      ->condition('id', $id)
      ->accessCheck(TRUE)
      ->execute();
    return (bool) $entity;
  }

  /**
   * Adds facet to the form.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $vals = $form_state->getValues();
    $vals['facets'][] = [];
    $form_state->setValues($vals);
    $form_state->setRebuild();
  }

  /**
   * Callback: Add facet form group.
   */
  public function addCallback(array &$form, FormStateInterface $form_state) {
    return $form['facets'];
  }

  /**
   * Callback: Remove facet form group.
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
  }

}
