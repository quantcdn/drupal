<?php

namespace Drupal\quant\Form;

use Drupal\node\Entity\Node;
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

/**
 * Contains a form for initializing a static build.
 *
 * @internal
 */
class SeedForm extends FormBase {

  use QuantStaticTrait;

  protected $client;

  protected $dispatcher;

  /**
   * Build the form.
   */
  public function __construct(QuantClientInterface $client, $event_dispatcher) {
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
   * {@inheritdoc}.
   */
  public function getFormId() {
    return 'quant_seed_form';
  }

  /**
   * {@inheritdoc}.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $warnings = $this->getWarnings();

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

    // @todo: Implement these as plugins.
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

    $form['redirects'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redirects'),
      '#description' => $this->t('Exports all existing redirects.'),
    ];

    $moduleHandler = \Drupal::moduleHandler();
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

    $config = $this->config('quant_api.settings');

    if ($config->get('api_token')) {
      if (!$project = $this->client->ping()) {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
        return;
      }
    }

    $assets = [];
    $routes = [];

    // Lunr.
    if ($form_state->getValue('lunr')) {
      // @TODO Sub-module for lunr support using the events.
      $assets = array_merge($assets, Seed::findLunrAssets());
      $routes = array_merge($routes, Seed::findLunrRoutes());
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
      $event = new CollectRedirectsEvent();
      $this->dispatcher->dispatch(QuantCollectionEvents::REDIRECT, $event);
      while ($redirect = $event->getEntity()) {
        $batch['operations'][] = ['\Drupal\quant\Seed::exportRedirect', [$redirect]];
      }
    }

    if ($form_state->getValue('entity_node')) {
      $event = new CollectEntitiesEvent();
      $this->dispatcher->dispatch(QuantCollectionEvents::ENTITY, $event);
      while ($entity = $event->getEntity()) {
        $batch['operations'][] = ['\Drupal\quant\Seed::exportNode', $entity];
      }
    }

    $event = new CollectRoutesEvent($routes);
    $this->dispatcher->dispatch(QuantCollectionEvents::ROUTE, $event);
    while ($route = $event->getRoute()) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportRoute', [$route]];
    }

    $event = new CollectFilesEvent($assets);
    $this->dispatcher->dispatch(QuantCollectionEvents::FILE, $event);
    while ($file = $event->getFilePath()) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportFile', [$file]];

    }

    batch_set($batch);
  }

}
