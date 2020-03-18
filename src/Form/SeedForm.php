<?php

namespace Drupal\quant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant\QuantStaticTrait;

/**
 * Contains a form for initializing a static build.
 *
 * @internal
 */
class SeedForm extends FormBase {

  use QuantStaticTrait;

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
      '#title' => $this->t('Export nodes'),
    ];

    $form['entity_node_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Export historic node revisions'),
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
      '#description' => $this->t('Theme images, fonts, favicon'),
    ];

    $form['entity_user'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Export user profiles'),
      '#disabled' => TRUE,
    ];

    $form['entity_media'] = [
      '#type' => 'checkbox',
      '#title' => 'Export media',
      '#description' => $this->t('Public media paths (e.g /media/123).'),
      '#disabled' => TRUE,
    ];

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

    $nids = [];
    $assets = [];

    // @todo: Separate plugins.
    if ($form_state->getValue('theme_assets')) {
      $assets = \Drupal\quant\Seed::findThemeAssets();
    }

    if ($form_state->getValue('entity_node')) {
      $query = \Drupal::entityQuery('node');
      $nids = $query->execute();
    }

    $batch = array(
      'title' => t('Exporting to Quant...'),
      'operations' => [],
      'init_message'     => t('Commencing'),
      'progress_message' => t('Processed @current out of @total.'),
      'error_message'    => t('An error occurred during processing'),
      'finished' => '\Drupal\quant\Seed::finishedSeedCallback',
    );

    // Add nodes to export batch.
    foreach ($nids as $key => $value) {
      $node = \Drupal\node\Entity\Node::load($value);

      // Export all node revisions.
      if ($form_state->getValue('entity_node_revisions')) {
        $vids = \Drupal::entityManager()->getStorage('node')->revisionIds($node);

        foreach ($vids as $vid) {
          $nr = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($vid);
          $batch['operations'][] = ['\Drupal\quant\Seed::exportNode',[$nr]];
        }
      }
      // Export current node revision.
      $batch['operations'][] = ['\Drupal\quant\Seed::exportNode',[$node]];
    }

    // Add assets to export batch.
    foreach ($assets as $file) {
      $batch['operations'][] = ['\Drupal\quant\Seed::exportFile',[$file]];
    }

    batch_set($batch);
  }

}
