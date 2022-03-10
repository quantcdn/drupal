<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

    $form['facets'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Facets'),
        '#prefix' => '<div id="facets-fieldset-wrapper">',
        '#suffix' => '</div>',
    ];

    $existingFacets = $this->entity->get('facets');

    $facets_count_field = $form_state->get('num_facets');
    if (empty($facets_count_field)) {
      $facets_count_field = $form_state->set('num_facets', 1);
      if (!empty($existingFacets)) {
        $facets_count_field = $form_state->set('num_facets', count($existingFacets));
      }
    }

    $facets_count_field = $form_state->get('num_facets');

    for ($i = 0; $i < $facets_count_field -1; $i++) {
      $form['facets'][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Facet configuration')
      ];

      $form['facets'][$i]['facet_filter'] = [
          '#type' => 'textfield',
          '#title' => t('Facet name'),
          '#default_value' => $existingFacets[$i]['facet_filter'],
      ];
      $form['facets'][$i]['facet_heading'] = [
        '#type' => 'textfield',
        '#title' => t('Facet heading'),
        '#default_value' => $existingFacets[$i]['facet_heading']
      ];
    }

    $form['facets']['actions']['add_facet'] = [
        '#type' => 'submit',
        '#value' => t('Add facet'),
        '#submit' => array('::addOne'),
        '#ajax' => [
            'callback' => '::addmoreCallback',
            'wrapper' => 'facets-fieldset-wrapper',
        ],
    ];
    if ($form_state->get('num_facets') > 1) {
        $form['facets']['actions']['remove_facet'] = [
            '#type' => 'submit',
            '#value' => t('Remove facet'),
            '#submit' => array('::removeCallback'),
            '#ajax' => [
                'callback' => '::addmoreCallback',
                'wrapper' => 'facets-fieldset-wrapper',
            ]
        ];
    }
    $form_state->setCached(FALSE);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {

    unset($form['facets']['actions']);
    $page = $this->entity;

    // Manually massage the facet configuration.
    $values = $form_state->getValue(array('facets'));
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

    \Drupal::service('router.builder')->rebuild();
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

  public function addOne(array &$form, FormStateInterface $form_state) {
    $facets_count_field = $form_state->get('num_facets');
    $add_button = $facets_count_field + 1;
    $form_state->set('num_facets', $add_button);
    $form_state->setRebuild();
  }

  public function addmoreCallback(array &$form, FormStateInterface $form_state) {
    $facets_count_field = $form_state->get('num_facets');
    return $form['facets'];
  }

  public function removeCallback(array &$form, FormStateInterface $form_state) {

    $facets_count_field = $form_state->get('num_facets');
    if ($facets_count_field > 1) {
      $remove_button = $facets_count_field - 1;
      $form_state->set('num_facets', $remove_button);
    }
    $form_state->setRebuild();
  }


}
