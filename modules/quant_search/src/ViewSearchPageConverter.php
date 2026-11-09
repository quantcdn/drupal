<?php

namespace Drupal\quant_search;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\views\ViewEntityInterface;

/**
 * Proposes a Quant search page from a View page display.
 *
 * The Quant search index holds a node's title, summary, content, content
 * type, language and the names of its taxonomy terms. So a View maps as
 * follows; everything else becomes a note:
 * - a fixed content type filter: the content types to search;
 * - an exposed content type, language or taxonomy filter: a facet;
 * - a fixed taxonomy filter: a filter on the term names;
 * - an exposed text filter: the keyword box;
 * - the pager: results per page.
 */
class ViewSearchPageConverter {

  use StringTranslationTrait;

  /**
   * Filter plugins on text that the keyword box replaces.
   */
  protected const TEXT_FILTERS = ['combine', 'string', 'search_keywords'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LanguageManagerInterface $languageManager,
    protected SearchBackend $backend,
  ) {}

  /**
   * The page displays of node Views that a search page can replace.
   *
   * @return array
   *   "view_id:display_id" => "View label: /path".
   */
  public function candidates(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('view')->loadMultiple() as $view) {
      if (!$view->status() || $view->get('base_table') !== 'node_field_data') {
        continue;
      }
      foreach ($view->get('display') as $display_id => $display) {
        if (($display['display_plugin'] ?? '') === 'page' && !empty($display['display_options']['path'])) {
          $options[$view->id() . ':' . $display_id] = $view->label() . ': /' . $display['display_options']['path'];
        }
      }
    }
    asort($options);
    return $options;
  }

  /**
   * The proposal for one View display.
   *
   * @return array|null
   *   NULL when the View or display does not exist. Otherwise:
   *   - values: title, route, bundles, facets (proposal keys), per_page,
   *     display_search, languages, manual_filters.
   *   - notes: what is not converted, and why (translated strings).
   *   - label: "View label (/path)".
   */
  public function propose(string $view_id, string $display_id): ?array {
    $view = $this->entityTypeManager->getStorage('view')->load($view_id);
    if (!$view instanceof ViewEntityInterface || !isset($view->get('display')[$display_id])) {
      return NULL;
    }
    $result = [
      'values' => [
        'title' => (string) ($this->option($view, $display_id, 'title') ?: $view->label()),
        'route' => (string) $this->option($view, $display_id, 'path'),
        'bundles' => [],
        'facets' => [],
        'per_page' => 20,
        'display_search' => FALSE,
        'languages' => [],
        'manual_filters' => [],
      ],
      'notes' => [],
      'label' => $view->label() . ' (/' . $this->option($view, $display_id, 'path') . ')',
    ];
    if ($view->get('base_table') !== 'node_field_data') {
      $result['notes'][] = $this->t('This View does not list content items, so nothing can be converted.');
      return $result;
    }
    foreach ((array) $this->option($view, $display_id, 'filters') as $filter) {
      $this->convertFilter((array) $filter, $result);
    }
    $this->convertPager((array) $this->option($view, $display_id, 'pager'), $result);
    $this->noteSortsAndArguments($view, $display_id, $result);
    $result['values']['facets'] = array_values(array_unique($result['values']['facets']));
    $result['values']['manual_filters'] = implode(' AND ', $result['values']['manual_filters']);
    return $result;
  }

  /**
   * A display option, falling back to the default display.
   */
  protected function option(ViewEntityInterface $view, string $display_id, string $name): mixed {
    $displays = $view->get('display');
    $own = $displays[$display_id]['display_options'] ?? [];
    if (array_key_exists($name, $own)) {
      return $own[$name];
    }
    return $displays['default']['display_options'][$name] ?? NULL;
  }

  /**
   * Converts one filter, or adds a note.
   */
  protected function convertFilter(array $filter, array &$result): void {
    $plugin = (string) ($filter['plugin_id'] ?? '');
    $exposed = !empty($filter['exposed']);
    $label = (string) ($filter['expose']['label'] ?? $filter['field'] ?? $plugin);
    match (TRUE) {
      $plugin === 'boolean' && ($filter['field'] ?? '') === 'status' => NULL,
      $plugin === 'bundle' => $this->convertBundle($filter, $exposed, $result),
      in_array($plugin, ['taxonomy_index_tid', 'taxonomy_index_tid_depth'], TRUE) => $this->convertTerms($filter, $exposed, $result),
      in_array($plugin, ['language', 'langcode'], TRUE) || ($filter['field'] ?? '') === 'langcode' => $this->convertLanguage($filter, $exposed, $result),
      $exposed && in_array($plugin, self::TEXT_FILTERS, TRUE) => $result['values']['display_search'] = TRUE,
      $exposed => $result['notes'][] = $this->t('The filter %label is not in the Quant search index, so it is not converted. Quant can filter by taxonomy terms, content type and language.', ['%label' => $label]),
      default => $result['notes'][] = $this->t('The condition on %label is not converted. The search index has no such field.', ['%label' => $label]),
    };
  }

  /**
   * A content type filter: fixed types to search, or a facet.
   */
  protected function convertBundle(array $filter, bool $exposed, array &$result): void {
    if ($exposed) {
      $result['values']['facets'][] = 'content_type';
      return;
    }
    $values = array_values(array_filter((array) ($filter['value'] ?? [])));
    $all = array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple());
    match ($filter['operator'] ?? 'in') {
      'in' => $result['values']['bundles'] = $values,
      // "None of": every other content type.
      'not in' => $result['values']['bundles'] = array_values(array_diff($all, $values)),
      default => $this->notConverted($filter, $result),
    };
  }

  /**
   * Notes a filter whose operator the search page cannot express.
   */
  protected function notConverted(array $filter, array &$result): void {
    $result['notes'][] = $this->t('The condition "@operator" on %label is not converted.', [
      '@operator' => (string) ($filter['operator'] ?? ''),
      '%label' => (string) ($filter['expose']['label'] ?? $filter['field'] ?? ''),
    ]);
  }

  /**
   * A taxonomy filter: a facet, or a filter on the term names.
   */
  protected function convertTerms(array $filter, bool $exposed, array &$result): void {
    $vid = (string) ($filter['vid'] ?? '');
    if ($vid === '') {
      $result['notes'][] = $this->t('A taxonomy filter without a vocabulary is not converted.');
      return;
    }
    if ($exposed) {
      $result['values']['facets'][] = 'taxonomy:' . $vid;
      return;
    }
    if (in_array($filter['operator'] ?? 'or', ['empty', 'not empty'], TRUE)) {
      $this->notConverted($filter, $result);
      return;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_filter((array) ($filter['value'] ?? [])));
    if ($terms === []) {
      return;
    }
    // Manual filters go to the search engine as written, so use its syntax.
    $resolved = $this->backend->get();
    $engine = is_array($resolved) && ($resolved['backend'] ?? '') === SearchBackend::TYPESENSE ? SearchBackend::TYPESENSE : SearchBackend::ALGOLIA;
    $field = $vid . '_' . $this->languageManager->getDefaultLanguage()->getId();
    $names = array_map(fn ($term) => $term->label(), $terms);
    $built = match ($filter['operator'] ?? 'or') {
      'or' => SearchBackend::anyOf($field, $names, $engine),
      'and' => SearchBackend::allOf($field, $names, $engine),
      'not' => SearchBackend::noneOf($field, $names, $engine),
      default => NULL,
    };
    if ($built === NULL) {
      $this->notConverted($filter, $result);
      return;
    }
    if ($built !== '') {
      $result['values']['manual_filters'][] = $built;
    }
  }

  /**
   * A language filter: a facet, or fixed languages.
   */
  protected function convertLanguage(array $filter, bool $exposed, array &$result): void {
    if ($exposed) {
      $result['values']['facets'][] = 'language';
      return;
    }
    $codes = array_values(array_filter((array) ($filter['value'] ?? []), fn ($code) => !str_starts_with((string) $code, '***')));
    match ($filter['operator'] ?? 'in') {
      'in' => $result['values']['languages'] = $codes,
      'not in' => $result['values']['languages'] = array_values(array_diff(array_keys($this->languageManager->getLanguages()), $codes)),
      default => $this->notConverted($filter, $result),
    };
  }

  /**
   * The pager: results per page.
   */
  protected function convertPager(array $pager, array &$result): void {
    $items = (int) ($pager['options']['items_per_page'] ?? 0);
    if ($items > 0) {
      $result['values']['per_page'] = max(5, min(100, $items));
    }
  }

  /**
   * Sorts and contextual filters are not converted: say so.
   */
  protected function noteSortsAndArguments(ViewEntityInterface $view, string $display_id, array &$result): void {
    $sorts = array_map(fn ($sort) => (string) ($sort['field'] ?? ''), (array) $this->option($view, $display_id, 'sorts'));
    if ($sorts !== []) {
      $result['notes'][] = $this->t('Quant orders results by relevance. The sort by @fields is not converted.', ['@fields' => implode(', ', $sorts)]);
    }
    if ($this->option($view, $display_id, 'arguments')) {
      $result['notes'][] = $this->t('Contextual filters are not converted.');
    }
  }

}
