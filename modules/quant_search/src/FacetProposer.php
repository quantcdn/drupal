<?php

namespace Drupal\quant_search;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Proposes search facets from the content types a search page covers.
 *
 * The index holds a node's content type, its language and the terms of every
 * vocabulary it references ({vocabulary}_{langcode}), so those are the
 * facets that can have values.
 */
class FacetProposer {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * The vocabularies that each content type references.
   *
   * @return array
   *   Vocabulary id => content type ids that reference it.
   */
  public function vocabularyUse(): array {
    $use = [];
    foreach (array_keys($this->entityTypeManager->getStorage('node_type')->loadMultiple()) as $bundle) {
      foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $field) {
        if ($field->getType() !== 'entity_reference' || $field->getSetting('target_type') !== 'taxonomy_term') {
          continue;
        }
        $targets = $field->getSetting('handler_settings')['target_bundles'] ?? [];
        foreach (array_keys((array) $targets) as $vid) {
          $use[$vid][$bundle] = $bundle;
        }
      }
    }
    return $use;
  }

  /**
   * The facets a page can offer, with a label and the default choice.
   *
   * @param string[] $bundles
   *   The content types the page searches; empty means every type.
   *
   * @return array
   *   Facet key ("content_type", "language" or "taxonomy:VID") =>
   *   ['label' => ..., 'default' => bool].
   */
  public function propose(array $bundles): array {
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $proposals = [];
    if (count($bundles) !== 1) {
      $proposals['content_type'] = ['label' => $this->t('Content type'), 'default' => TRUE];
    }
    if (count($this->languageManager->getLanguages()) > 1) {
      $proposals['language'] = ['label' => $this->t('Language'), 'default' => FALSE];
    }
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    foreach ($this->vocabularyUse() as $vid => $users) {
      if (!isset($vocabularies[$vid])) {
        continue;
      }
      $relevant = $bundles === [] ? $users : array_intersect_key($users, array_flip($bundles));
      $names = array_map(fn ($bundle) => isset($types[$bundle]) ? $types[$bundle]->label() : $bundle, $users);
      $proposals['taxonomy:' . $vid] = [
        'label' => $this->t('@vocabulary (used by @types)', [
          '@vocabulary' => $vocabularies[$vid]->label(),
          '@types' => implode(', ', $names),
        ]),
        'default' => $relevant !== [],
      ];
    }
    return $proposals;
  }

  /**
   * Turns chosen proposal keys into search page facets.
   *
   * @param string[] $keys
   *   Keys from propose().
   * @param string $display
   *   The facet display: checkbox, select or menu.
   *
   * @return array
   *   Facets in the format of the quant_search_page entity.
   */
  public function facets(array $keys, string $display): array {
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    $facets = [];
    foreach (array_values($keys) as $weight => $key) {
      [$type, $vid] = array_pad(explode(':', $key, 2), 2, '');
      $heading = match ($type) {
        'content_type' => (string) $this->t('Content type'),
        'language' => (string) $this->t('Language'),
        default => isset($vocabularies[$vid]) ? $vocabularies[$vid]->label() : $vid,
      };
      $facets[] = [
        'facet_display' => $display,
        'facet_type_config' => [
          'facet_type' => $type,
          'taxonomy_vocabulary' => $type === 'taxonomy' ? $vid : '',
          'custom_key' => '',
        ],
        'facet_heading' => $heading,
        'facet_language' => $langcode,
        'facet_limit' => 10,
        'weight' => $weight,
      ];
    }
    return $facets;
  }

}
