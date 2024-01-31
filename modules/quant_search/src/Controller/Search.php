<?php

namespace Drupal\quant_search\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\quant\Seed;
use Drupal\quant\Utility;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class Search extends ControllerBase {

  const SETTINGS = 'quant_api.settings';

  /**
   * The Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface
   */
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
   * {@inheritdoc}
   */
  public function statusPage() {
    $config = $this->config(self::SETTINGS);

    $searchEnabled = $this->enabled();
    if (!$searchEnabled) {
      if ($config->get('api_token') && $project = $this->client->project()) {
        \Drupal::messenger()->addError(t('Search is not enabled for this project. It can be enabled via the Quant Dashboard. See <a href="https://docs.quantcdn.io/docs/dashboard/search">Quant Search documentation</a>.'));
      }
      else {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API. See <a href="https://docs.quantcdn.io/docs/integrations/drupal#setup">Quant setup documentation</a>.'));
      }
    }

    // Retrieve search stats.
    $search = $this->client->search();

    if (!$searchEnabled || !isset($search->index)) {
      $markup = $searchEnabled ? $this->t('Unable to retrieve search index values.') : '';
      return [
        '#markup' => $markup,
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

    $facetKeys = self::processTranslatedFacetKeys($facets);

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
          'quant_search/algolia-page',
          'quant_search/search-page',
        ],
        'drupalSettings' => [
          'quantSearch' => [
            'algolia_application_id' => $project->config->search_index->algolia_application_id,
            'algolia_read_key' => $project->config->search_index->algolia_read_key,
            'algolia_index' => $project->config->search_index->algolia_index,
            'filters' => $filtersString,
            'facets' => $facetKeys,
            'display' => $page->get('display'),
          ],
        ],
      ],
      '#index' => $project->config->search_index,
      '#page' => $page->toArray(),
      '#facets' => $facetKeys,
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
    else {
      $langcode = $entity->language()->getId();
    }

    // Get override values.
    $typeConfig = \Drupal::config('quant_search.entities.settings.' . $entity->bundle());

    // Determine whether this entity should be skipped.
    $skipRecord = FALSE;
    $nodeEnabled = $config->get('quant_search_entity_node');
    $taxonomyEnabled = $config->get('quant_search_entity_taxonomy_term');

    if ($entity->getEntityTypeId() == 'node' && !$nodeEnabled) {
      $skipRecord = TRUE;
    }

    // Skip if 'exclude' is set on this type.
    if (!empty($typeConfig) && $typeConfig->get('exclude')) {
      $skipRecord = TRUE;
    }

    // Determine whether language should be skipped.
    $allowedLanguages = $config->get('quant_search_entity_node_languages');

    if (!empty($allowedLanguages)) {
      $allowedLanguages = array_filter($allowedLanguages);
      if (!empty($allowedLanguages)) {
        if (!in_array($langcode, $allowedLanguages)) {
          $skipRecord = TRUE;
        }
      }
    }

    // Determine whether the search record should be created or skipped.
    $allowedBundles = $config->get('quant_search_entity_node_bundles');

    if (!empty($allowedBundles)) {
      $allowedBundles = array_filter($allowedBundles);
      if (!empty($allowedBundles)) {
        if (!in_array($entity->bundle(), $allowedBundles)) {
          $skipRecord = TRUE;
        }
      }
    }

    // Skip search record.
    if ($skipRecord) {
      $record = [
        'skip' => TRUE,
      ];
      return $record;
    }

    // Get default values.
    $titleToken = $config->get('quant_search_title_token');
    $summaryToken = $config->get('quant_search_summary_token');
    $imageToken = $config->get('quant_search_image_token');
    $viewMode = $config->get('quant_search_content_viewmode');

    if (!empty($typeConfig) && $typeConfig->get('enabled')) {
      $titleToken = $typeConfig->get('quant_search_title_token');
      $summaryToken = $typeConfig->get('quant_search_summary_token');
      $imageToken = $typeConfig->get('quant_search_image_token');
      $viewMode = $typeConfig->get('quant_search_content_viewmode');
    }

    // Get token values from context.
    $context = [];
    $context[$entityType] = $entity;

    $title = \Drupal::token()->replace($titleToken, $context, [
      'langcode' => $langcode,
      'clear' => TRUE,
    ]);
    $summary = \Drupal::token()->replace($summaryToken, $context, [
      'langcode' => $langcode,
      'clear' => TRUE,
    ]);
    $image = \Drupal::token()->replace($imageToken, $context, [
      'langcode' => $langcode,
      'clear' => TRUE,
    ]);

    $view_builder = \Drupal::entityTypeManager()->getViewBuilder($entityType);
    $build = $view_builder->view($entity, $viewMode, $langcode);
    $output = \Drupal::service('renderer')->renderRoot($build);

    $record = [];

    if (!empty($title)) {
      $record['title'] = self::sanitizeValues($title);
    }

    if (!empty($summary)) {
      $record['summary'] = self::sanitizeValues($summary);
    }

    if (!empty($output)) {
      $record['content'] = self::sanitizeValues($output);
    }

    if (!empty($image)) {
      $record['image'] = self::sanitizeValues($image);
      // Rewrite images as relative paths.
      $record['image'] = Seed::rewriteRelative($record['image']);
    }

    if (!empty($langcode)) {
      $language = \Drupal::languageManager()->getLanguage($langcode);
      $record['lang_code'] = $langcode;

      foreach ($entity->getTranslationLanguages() as $code => $lang) {
        $language_label = \Drupal::service('string_translation')->translate($language->getName(), [], ['langcode' => $code]);
        $record["language_{$code}"] = $language_label;
      }
    }

    // @todo Update node-only logic.
    $record['url'] = Utility::getCanonicalUrl('node', $entity->id(), $langcode);

    // Add search meta for node entities.
    if ($entity->getEntityTypeId() == 'node') {
      $record += self::getNodeTerms($entity, $langcode);
      $record["content_type"] = $entity->type->entity->id();

      $translated_type = \Drupal::service('string_translation')->translate($entity->type->entity->label(), [], ['langcode' => $langcode]);
      $record["content_type_{$langcode}"] = $translated_type;
    }

    return $record;
  }

  /**
   * Retrieves any terms attached to a given node.
   *
   * @param Drupal\node\Entity\Node $entity
   *   The entity to gather info for.
   * @param string $langcode
   *   The language code for the entity.
   *
   * @return array
   *   Terms tagged to the node.
   */
  private static function getNodeTerms(Node $entity, $langcode) {
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
      $entity = $term->getTranslation($langcode);
      $key = $term->bundle() . '_' . $langcode;
      $terms[$key][] = $entity->label();
    }

    return $terms;
  }

  /**
   * Determine translated facet keys.
   *
   * @param array $facets
   *   The facets array attached to the custom entity.
   *
   * @return array
   *   The processed array.
   */
  public static function processTranslatedFacetKeys(array $facets) {

    $keys = [];
    foreach ($facets as $k => $facet) {
      $lang = $facet['facet_language'];

      switch ($facet['facet_type_config']['facet_type']) {
        case "taxonomy":
          $key = $facet['facet_type_config']['taxonomy_vocabulary'] . '_' . $lang;
          $containerKey = $key . "_{$k}";
          $facet['facet_key'] = $key;
          $facet['facet_container'] = $containerKey;
          $keys[$containerKey] = $facet;
          break;

        case "content_type":
          $key = "content_type_{$lang}";
          $containerKey = $key . "_{$k}";
          $facet['facet_key'] = $key;
          $facet['facet_container'] = $containerKey;
          $keys[$containerKey] = $facet;
          break;

        case "language":
          $key = "language_{$lang}";
          $containerKey = $key . "_{$k}";
          $facet['facet_key'] = $key;
          $facet['facet_container'] = $containerKey;
          $keys[$containerKey] = $facet;
          break;

        case "custom":
          // @todo Allow custom entity key.
          break;

        default:
          // Nothing to do.
      }
    }

    return $keys;
  }

  /**
   * Checks search administration access.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function administerAccess(AccountInterface $account) {
    // In order to hide search configuration options when search is disabled,
    // use this rather than just the `administer quant search` permission.
    return AccessResult::allowedIf($account->hasPermission('administer quant search') && $this->enabled());
  }

  /**
   * Checks search is enabled.
   */
  public function enabled() {
    $config = $this->config(self::SETTINGS);

    $searchEnabled = ($config->get('api_token') && $this->client->project() && $this->client->project()->config->search_enabled);
    if ($searchEnabled) {
      \Drupal::messenger()->addMessage(t('Search is enabled for project %api.', ['%api' => $config->get('api_project')]));
    }

    return $searchEnabled;
  }

  /**
   * Sanitises values to send to Algolia.
   */
  private static function sanitizeValues($value) {
    // Ensures &#[0-9]+ entities are converted to utf-8.
    $value = preg_replace_callback("/(&#[0-9]+;)/", function ($m) {
      return mb_convert_encoding($m[1], "UTF-8", "HTML-ENTITIES");
    }, $value);
    // Ensure standard html entities are decoded and tags are stripped.
    $value = strip_tags(html_entity_decode($value));
    return $value;
  }

}
