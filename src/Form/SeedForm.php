<?php

namespace Drupal\quant\Form;

use Drupal\node\Entity\Node;
use Drupal\quant\Seed;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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

    // Seed by bundle.
    $types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    foreach($types as $type) {
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

    $nids = [];
    $assets = [];
    $routes = [];

    // @todo: Separate plugins.
    if ($form_state->getValue('theme_assets')) {
      $assets = Seed::findThemeAssets();
    }

    // Lunr.
    if ($form_state->getValue('lunr')) {
      $assets = array_merge($assets, Seed::findLunrAssets());
      $routes = array_merge($routes, Seed::findLunrRoutes());
    }

    // Views.
    if ($form_state->getValue('views_pages')) {
      $routes = array_merge($routes, Seed::findViewRoutes());
    }

    if ($form_state->getValue('redirects')) {
      $redirects = Seed::findRedirects();
    }

    if ($form_state->getValue('entity_node')) {
      $query = \Drupal::entityQuery('node');

      // Restrict by bundle.
      if (!empty($bundles = array_filter($form_state->getValue('entity_node_bundles')))) {
        if (!empty($bundles)) {
          $query->condition('type', array_keys($bundles), 'IN');
        }
      }

      $nids = $query->execute();
    }

    $batch = [
      'title' => t('Exporting to Quant...'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\quant\Seed::finishedSeedCallback',
    ];

    // Add redirects to export batch.
    foreach ($redirects as $redirect) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportRedirect', [$redirect]];
    }

    // Add nodes to export batch.
    foreach ($nids as $key => $value) {
      $node = Node::load($value);

      // Export all node revisions.
      if ($form_state->getValue('entity_node_revisions')) {
        $vids = \Drupal::entityManager()->getStorage('node')->revisionIds($node);

        foreach ($vids as $vid) {
          $nr = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($vid);
          $batch['operations'][] = ['\Drupal\quant\Seed::exportNode', [$nr]];
        }
      }
      // Export current node revision.
      $batch['operations'][] = ['\Drupal\quant\Seed::exportNode', [$node]];
    }

    // Add assets to export batch.
    foreach ($assets as $file) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportFile', [$file]];
    }

    // Add arbitrary routes to export batch.
    foreach ($routes as $route) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportRoute', [$route]];
    }

    batch_set($batch);
  }

}
