<?php

namespace Drupal\quant_purger\Plugin\views\cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\views\Plugin\views\cache\Tag;

/**
 * Add node type tags to views where available to support CRUD.
 *
 * @ViewsCache(
 *  id = "quant_views_tag_caching",
 *  title = @Translation("Quant tag-based caching"),
 *  help = @Translation("Add a standard node type tag to views")
 * )
 */
class QuantTagViews extends Tag {

  use MessengerTrait;

  /**
   * Overrides Drupal\views\Plugin\Plugin::$usesOptions.
   *
   * @var bool
   */
  protected $usesOptions = TRUE;

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    return $this->t('Entity types');
  }

  /**
   * {@inheritdoc}
   */
  public function defineOptions() {
    $options = parent::defineOptions();
    $options['entity_types'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $options = [];

    $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    foreach ($entity_types as $entity_type) {
      $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type->id());
      if (!in_array($entity_type->id(), ['node'])) {
        // @TODO: Expand cache tag selection options.
        continue;
      }
      foreach ($bundles as $id => $bundle) {
        $key = "quant:{$entity_type->id()}:{$id}";
        $options[$key] = "{$entity_type->getLabel()} {$bundle['label']}";
      }
    }

    $form['entity_types'] = [
      '#type' => 'checkboxes',
      '#multiple' => TRUE,
      '#title' => $this->t('Entity types'),
      '#description' => $this->t('Choose entity types that this view will display.'),
      '#default_value' => $this->options['entity_types'],
      '#options' => $options,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = parent::getCacheTags();

    // Remove the the list cache tags for the entity types listed in this view.
    // @see CachePluginBase::getCacheTags().
    $entity_information = $this->view->getQuery()->getEntityTableInfo() ?? [];

    // Add the list cache tags for each entity type used by this view.
    foreach ($entity_information as $table => $metadata) {
      $remove = \Drupal::entityTypeManager()->getDefinition($metadata['entity_type'])->getListCacheTags();
      $tags = array_diff($tags, $remove);
    }

    return Cache::mergeTags($tags, array_values(array_filter($this->options['entity_types'])));
  }

}
