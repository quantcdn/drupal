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
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'quant.settings';

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
    $seed_config = $this->config(static::SETTINGS);
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
      '#default_value' => $seed_config->get('entity_node'),
      '#title' => $this->t('Nodes'),
      '#description' => $this->t('Exports node content entities.'),
    ];

    $form['node_details'] = [
      '#type' => 'details',
      '#tree' => FALSE,
      '#title' => $this->t('Node configuration'),
      '#states' => [
        'visible' => [
          ':input[name="entity_node"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['node_details']['entity_seed_method'] = [
      '#type' => 'radios',
      '#description' => $this->t('Controls the seed method for how nodes are sent to Quant.'),
      '#title' => $this->t('Node seed method'),
      '#options' => [
        'published' => $this->t('Published'),
        'revisions' => $this->t('Revision history'),
      ],
      '#default_value' => $seed_config->get('entity_node_revisions') ? 'revisions' : 'published',
    ];

    $form['node_details']['entity_node_published_info'] = [
      '#type' => 'container',
      '#markup' => $this->t('Push revision history independently of published revisions for best results.'),
      '#states' => [
        'visible' => [
          ':input[name="entity_seed_method"]' => ['value' => 'published'],
        ],
      ],
    ];

    $form['node_details']['entity_node_revisions_info'] = [
      '#type' => 'container',
      '#markup' => $this->t('Exports the historic revision history for nodes. <em>Note: You should only perform this operation this once.</em>'),
      '#states' => [
        'visible' => [
          ':input[name="entity_seed_method"]' => ['value' => 'revisions'],
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

      $form['node_details']['entity_node_languages'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Languages'),
        '#description' => $this->t('Optionally restrict to these languages. If no options are selected all languages will be exported.'),
        '#options' => $language_codes,
        '#default_value' => $seed_config->get('entity_node_languages') ?: [],
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

    $form['node_details']['entity_node_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled bundles'),
      '#description' => $this->t('Optionally restrict to these content types.'),
      '#options' => $content_types,
      '#default_value' => $seed_config->get('entity_node_bundles') ?: [],
    ];

    $form['entity_taxonomy_term'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Taxonomy terms'),
      '#description' => $this->t('Exports taxonomy term pages.'),
      '#default_value' => $seed_config->get('entity_taxonomy_term'),
    ];

    // @todo Implement these as plugins.
    $form['theme_assets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Theme assets'),
      '#description' => $this->t('Images, fonts and favicon in the public theme.'),
      '#default_value' => $seed_config->get('theme_assets'),
    ];

    $form['views_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Views (Pages)'),
      '#description' => $this->t('Exports all views with a Page display accessible to anonymous users.'),
      '#default_value' => $seed_config->get('views_pages'),
    ];

    if ($moduleHandler->moduleExists('redirect')) {
      $form['redirects'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Redirects'),
        '#description' => $this->t('Exports all existing redirects.'),
        '#default_value' => $seed_config->get('redirects'),
      ];
    }

    $form['routes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Custom routes'),
      '#description' => $this->t('Exports custom list of individual routes.  May be content or files.'),
      '#default_value' => $seed_config->get('routes'),
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
      '#default_value' => $seed_config->get('routes_textarea'),
    ];

    $form['file_paths'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('File paths'),
      '#description' => $this->t('Exports files with support for wildcards.'),
      '#default_value' => $seed_config->get('file_paths'),
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
      '#default_value' => $seed_config->get('file_paths_textarea'),
    ];

    $form['robots'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Robots.txt'),
      '#description' => $this->t('Export robots.txt to Quant.'),
      '#default_value' => $seed_config->get('robots'),
    ];

    if ($moduleHandler->moduleExists('lunr')) {
      $form['lunr'] = [
        '#type' => 'checkbox',
        '#title' => 'Lunr search assets',
        '#description' => $this->t('Exports required lunr javascript libraries and all search indexes for decoupled search.'),
        '#default_value' => $seed_config->get('lunr'),
      ];
    }

    $form['trigger_quant_seed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Trigger the batch'),
      '#description' => $this->t('<strong>Note:</strong> This will attempt to trigger the seed from the UI, depending on the size of your site and PHP configuration this may not work.'),
      '#weight' => 50,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#op' => 'save',
      '#attributes' => [
        'class' => ['button--primary'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and Queue'),
      '#op' => 'queue',
      '#attributes' => [
        'class' => ['button--secondary'],
      ],
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
    $trigger = $form_state->getTriggeringElement();

    $this->configFactory->getEditable(static::SETTINGS)
      ->set('entity_node', $form_state->getValue('entity_node'))
      ->set('entity_node_languages', $form_state->getValue('entity_node_languages'))
      ->set('entity_node_bundles', $form_state->getValue('entity_node_bundles'))
      ->set('entity_node_revisions', $form_state->getValue('entity_seed_method') == 'revisions')
      ->set('entity_taxonomy_term', $form_state->getValue('entity_taxonomy_term'))
      ->set('theme_assets', $form_state->getValue('theme_assets'))
      ->set('views_pages', $form_state->getValue('views_pages'))
      ->set('redirects', $form_state->getValue('redirects'))
      ->set('routes', $form_state->getValue('routes'))
      ->set('routes_textarea', $form_state->getValue('routes_textarea'))
      ->set('file_paths', $form_state->getValue('file_paths'))
      ->set('file_paths_textarea', $form_state->getValue('file_paths_textarea'))
      ->set('robots', $form_state->getValue('robots'))
      ->set('lunr', $form_state->getValue('lunr'))
      ->save();

    if (isset($trigger['#op']) && $trigger['#op'] == 'save') {
      \Drupal::messenger()->addStatus(t('Successfully updated configuration.'));
      return;
    }

    if ($config->get('api_token')) {
      if (!$project = $this->client->ping()) {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
        return;
      }
    }

    $form_state->setValue('entity_node_revisions', $form_state->getValue('entity_seed_method') == 'revisions');

    $assets = [];
    $routes = [];

    // Lunr.
    if ($form_state->getValue('lunr')) {
      // @todo Sub-module for lunr support using the events.
      $assets = array_merge($assets, Seed::findLunrAssets());
      $routes = array_merge($routes, Seed::findLunrRoutes());
    }

    if ($form_state->getValue('routes_textarea')) {
      foreach (explode(PHP_EOL, $form_state->getValue('routes')) as $route) {
        if (strpos((trim($route)), '/') !== 0) {
          continue;
        }
        $routes[] = trim($route);
      }
    }

    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get('quant_seed_worker');
    $queue->deleteQueue();

    if ($form_state->getValue('redirects')) {
      // Collect the redirects for the seed.
      $event = new CollectRedirectsEvent($form_state);
      $this->dispatcher->dispatch($event, QuantCollectionEvents::REDIRECTS);
    }

    if ($form_state->getValue('entity_node') || $form_state->getValue('entity_node_revisions')) {
      $event = new CollectEntitiesEvent($form_state);
      $this->dispatcher->dispatch($event, QuantCollectionEvents::ENTITIES);
    }

    $event = new CollectRoutesEvent($form_state);
    $this->dispatcher->dispatch($event, QuantCollectionEvents::ROUTES);

    foreach ($routes as $route) {
      $event->queueItem($route);
    }

    $event = new CollectFilesEvent($form_state);
    $this->dispatcher->dispatch($event, QuantCollectionEvents::FILES);

    foreach ($assets as $asset) {
      $event->queueItem($asset);
    }

    if ($form_state->getValue('trigger_quant_seed')) {
      $batch = [
        'title' => $this->t('Exporting to Quant...'),
        'operations' => [],
        'init_message'     => $this->t('Commencing'),
        'progress_message' => $this->t('Processed @current out of @total.'),
        'error_message'    => $this->t('An error occurred during processing'),
      ];
      $batch['operations'][] = ['quant_process_queue', []];
      batch_set($batch);
    }
    else {
      \Drupal::messenger()->addStatus($this->t('Queued %total items to send to Quant.', [
        '%total' => $queue->numberOfItems(),
      ]));
    }
  }

}
