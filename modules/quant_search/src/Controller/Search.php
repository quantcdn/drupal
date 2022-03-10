<?php

namespace Drupal\quant_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class Search extends ControllerBase {

  const SETTINGS = 'quant_api.settings';

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
   * {@inheritdoc}
   */
  public function statusPage() {
    $config = $this->config(self::SETTINGS);

    if ($config->get('api_token')) {
      if ($project = $this->client->project()) {
        if ($project->config->search_enabled) {
          $message = t('Search is enabled for @api', ['@api' => $config->get('api_project')]);
          \Drupal::messenger()->addMessage($message);
        }
        else {
          \Drupal::messenger()->addError(t('Search is not enabled for this project. Enable via the Quant Dashboard.'));
        }
      }
      else {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
      }
    }

    // Retrieve search stats.
    $search = $this->client->search();

    if (!isset($search->index)) {
      return [
        '#markup' => $this->t('Unable to retrieve search index values.')
      ];
    }

    return [
      '#theme' => 'search_page_status',
      '#index' => $search->index,
      '#settings' => $search->settings,
      '#pages' => NULL,
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function searchPage($page) {

    $project = $this->client->project();

    $languages = $page->get('languages');
    $bundles = $page->get('bundles');
    $manualFilters = $page->get('manual_filters');
    $facets = $page->get('facets');
    unset($facets['actions']);

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
      '#theme' => 'search_page',
      '#attached' => [
        'library' => [
          'quant_search/algolia',
        ],
        'drupalSettings' => [
          'quantSearch' => [
            'algolia_application_id' => $project->config->search_index->algolia_application_id,
            'algolia_read_key' => $project->config->search_index->algolia_read_key,
            'algolia_index' => $project->config->search_index->algolia_index,
            'filters' => $filtersString,
            'facets' => $facets,
          ]
        ]
      ],
      '#index' => $project->config->search_index,
      '#page' => $page->toArray(),
    ];
  }

  /**
   * Generate the search record for an entity.
   */
  public static function generateSearchRecord($entity, $langcode = NULL) {

    $config = \Drupal::config('quant_search.entities.settings');

    // Bail if entity is undefined.
    if (empty($entity)) {
      return;
    }

    $entityType = $entity->getEntityTypeId();
    if (!empty($langcode)) {
      $entity = $entity->getTranslation($langcode);
    }

    // Get token values from context.
    $ctx = [];
    $ctx[$entityType] = $entity;

    $title = \Drupal::token()->replace($config->get('quant_search_title_token'), $ctx, ['langcode' => $langcode, 'clear' => TRUE]);
    $summary = \Drupal::token()->replace($config->get('quant_search_summary_token'), $ctx, ['langcode' => $langcode, 'clear' => TRUE]);
    $image = \Drupal::token()->replace($config->get('quant_search_image_token'), $ctx, ['langcode' => $langcode, 'clear' => TRUE]);

    $view_builder = \Drupal::entityTypeManager()->getViewBuilder($entityType);
    $view_mode = $config->get('quant_search_content_viewmode');
    $build = $view_builder->view($entity, $view_mode, $langcode);
    $output = render($build);

    $record = [];
    $record['title'] = $title;
    $record['summary'] = strip_tags(html_entity_decode($summary));
    $record['content'] = strip_tags(html_entity_decode($output));
    $record['image'] = strip_tags(html_entity_decode($image));

    $options = ['absolute' => FALSE];
    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $options['language'] = $language;
    }

    // @todo: Node only logic..
    $record['url'] = Url::fromRoute('entity.node.canonical', ['node' => $entity->id()], $options)->toString();

    // Add search meta for node entities.
    if ($entity->getEntityTypeId() == 'node') {

      $record += self::getNodeTerms($entity);
      $record['content_type'] = $entity->type->entity->label();
    }

    $record['lang_code'] = $langcode;
    return $record;
  }

  /**
   * Retrieves any terms attached to a given node.
   *
   * @param Drupal\node\Entity\Node $entity
   *   The entity to gather info for.
   *
   * @return array
   *   Terms tagged to the node.
   */
  private static function getNodeTerms(Node $entity) {
    $query = \Drupal::database()
      ->select('taxonomy_index', 'ti')
      ->fields('ti', ['tid'])
      ->condition('nid', $entity->id());

    $results = $query->execute()->fetchCol();

    $tids = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadMultiple($results);

    $terms = [];

    foreach ($tids as $term) {
      $terms[$term->bundle()][] = $term->label();
    }

    return $terms;
  }

}
