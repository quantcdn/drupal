<?php

namespace Drupal\quant\Form;

use Drupal\quant\Seed;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant\Event\CollectEntitiesEvent;
use Drupal\quant\Event\CollectFilesEvent;
use Drupal\quant\Event\CollectRedirectsEvent;
use Drupal\quant\Event\CollectRoutesEvent;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\quant\QuantStaticTrait;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Contains a form for initializing a static build.
 *
 * @internal
 */
class SeedForm extends FormBase {

  use QuantStaticTrait;

  /**
   * The client.
   *
   * @var Drupal\quant_api\Client\QuantClientInterface
   */
  protected $client;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $dispatcher;

  /**
   * Build the form.
   */
  public function __construct(QuantClientInterface $client, EventDispatcherInterface $event_dispatcher) {
    $this->client = $client;
    $this->dispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quant_api.client'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_seed_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $warnings = $this->getWarnings();
    $config = $this->config('quant_api.settings');
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
    ];

    $form['entity_node_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('All revisions'),
      '#description' => $this->t('Exports all historic revisions.'),
      '#states' => [
        'visible' => [
          ':input[name="entity_node"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['entity_taxonomy_term'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Taxonomy terms'),
      '#description' => $this->t('Exports taxonomy term pages.'),
    ];

    // @todo Implement these as plugins.
    $form['theme_assets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Theme assets'),
      '#description' => $this->t('Images, fonts and favicon in the public theme.'),
    ];

    $form['views_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Views (Pages)'),
      '#description' => $this->t('Exports all views with a Page display accessible to anonymous users.'),
    ];

    if ($moduleHandler->moduleExists('redirect')) {
      $form['redirects'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Redirects'),
        '#description' => $this->t('Exports all existing redirects.'),
      ];
    }

    $form['routes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Custom routes'),
      '#description' => $this->t('Exports custom list of routes.'),
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

    $form['robots'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Robots.txt'),
      '#description' => $this->t('Export robots.txt to Quant.'),
    ];

    if ($moduleHandler->moduleExists('lunr')) {
      $form['lunr'] = [
        '#type' => 'checkbox',
        '#title' => 'Lunr search assets',
        '#description' => $this->t('Exports required lunr javascript libraries and all search indexes for decoupled search.'),
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start batch'),
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
    $config = $this->configFactory->getEditable('quant_api.settings');

    if ($config->get('api_token')) {
      if (!$project = $this->client->ping()) {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
        return;
      }
    }

    $assets = [];
    $routes = [];
    $redirects = [];

    // Lunr.
    if ($form_state->getValue('lunr')) {
      // @todo Sub-module for lunr support using the events.
      $assets = array_merge($assets, Seed::findLunrAssets());
      $routes = array_merge($routes, Seed::findLunrRoutes());
    }

    $config->set('routes', $form_state->getValue('routes'))->save();
    $config->set('routes_export', $form_state->getValue('routes_textarea'))->save();

    if ($form_state->getValue('routes_textarea')) {
      foreach (explode(PHP_EOL, $form_state->getValue('routes')) as $route) {
        if (strpos((trim($route)), '/') !== 0) {
          continue;
        }
        $routes[] = trim($route);
      }
    }

    $batch = [
      'title' => t('Exporting to Quant...'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\quant\Seed::finishedSeedCallback',
    ];

    if ($form_state->getValue('redirects')) {
      // Collect the redirects for the seed.
      $event = new CollectRedirectsEvent([], $form_state);
      $this->dispatcher->dispatch(QuantCollectionEvents::REDIRECTS, $event);
      while ($redirect = $event->getEntity()) {
        $batch['operations'][] = [
          '\Drupal\quant\Seed::exportRedirect',
          [$redirect],
        ];
      }
    }

    if ($form_state->getValue('entity_node')) {
      $revisions = $form_state->getValue('entity_node_revisions');
      $event = new CollectEntitiesEvent([], $revisions, $form_state);
      $this->dispatcher->dispatch(QuantCollectionEvents::ENTITIES, $event);
      while ($entity = $event->getEntity()) {
        $batch['operations'][] = ['\Drupal\quant\Seed::exportNode', [$entity]];
      }
    }

    $event = new CollectRoutesEvent($routes, $form_state);
    $this->dispatcher->dispatch(QuantCollectionEvents::ROUTES, $event);

    while ($route = $event->getRoute()) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportRoute', [$route]];
    }

    $event = new CollectFilesEvent($assets, $form_state);
    $this->dispatcher->dispatch(QuantCollectionEvents::FILES, $event);
    while ($file = $event->getFilePath()) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportFile', [$file]];
    }

    batch_set($batch);
  }

}
