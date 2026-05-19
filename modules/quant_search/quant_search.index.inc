<?php

/**
 * @file
 * Quant Search indexing: record generation and batch re-index.
 */

/**
 * Builds a search record for one node.
 *
 * @param object $node
 *   The node.
 *
 * @return array|false
 *   The record array, or FALSE if the node's bundle is not indexed.
 */
function quant_search_generate_record($node) {
  $config = variable_get('quant_search_entities', array());
  if (empty($config['node']['enabled']) || empty($config['node']['bundles'])
      || !in_array($node->type, $config['node']['bundles'], TRUE)) {
    return FALSE;
  }

  $langcode = !empty($node->language) && $node->language != LANGUAGE_NONE ? $node->language : 'en';
  $record = array();

  // Token-driven fields.
  $data = array('node' => $node);
  $opts = array('clear' => TRUE, 'sanitize' => FALSE);
  foreach (array('title' => 'title_token', 'summary' => 'summary_token', 'image' => 'image_token') as $key => $cfg) {
    if (!empty($config['node'][$cfg])) {
      $value = trim(token_replace($config['node'][$cfg], $data, $opts));
      if ($value !== '') {
        $record[$key] = ($key === 'image') ? $value : decode_entities(strip_tags($value));
      }
    }
  }

  // Rendered body.
  $view_mode = !empty($config['node']['view_mode']) ? $config['node']['view_mode'] : 'full';
  $build = node_view($node, $view_mode);
  $record['content'] = decode_entities(trim(strip_tags(drupal_render($build))));

  // Core attributes.
  $uri = entity_uri('node', $node);
  $record['url'] = '/' . drupal_get_path_alias($uri['path']);
  $record['lang_code'] = $langcode;
  $record['content_type'] = $node->type;
  $type_info = node_type_get_type($node);
  $record['content_type_' . $langcode] = $type_info ? $type_info->name : $node->type;

  // Taxonomy terms, grouped by vocabulary.
  $record += quant_search_node_terms($node, $langcode);

  // Date fields as numeric timestamps.
  $date_fields = isset($config['node']['date_fields']) ? $config['node']['date_fields'] : '';
  $record += quant_search_node_dates($node, $date_fields);

  return $record;
}

/**
 * Returns taxonomy term names for a node, keyed {vocabulary}_{langcode}.
 *
 * @param object $node
 *   The node.
 * @param string $langcode
 *   The node's language code, appended to each vocabulary key.
 *
 * @return array
 *   Map of {vocabulary_machine_name}_{langcode} => array of term names.
 */
function quant_search_node_terms($node, $langcode) {
  $out = array();
  $tids = db_query('SELECT tid FROM {taxonomy_index} WHERE nid = :nid', array(':nid' => $node->nid))->fetchCol();
  if (!$tids) {
    return $out;
  }
  foreach (taxonomy_term_load_multiple($tids) as $term) {
    $vocab = taxonomy_vocabulary_load($term->vid);
    if (!$vocab) {
      continue;
    }
    $key = $vocab->machine_name . '_' . $langcode;
    $out[$key][] = $term->name;
  }
  return $out;
}

/**
 * Extracts date-field values from a node as numeric Unix timestamps.
 *
 * @param object $node
 *   The node.
 * @param string $date_fields
 *   Comma-separated date field machine names.
 *
 * @return array
 *   Map of {field_name} => array of integer timestamps. Multi-value and
 *   recurring date fields yield multiple timestamps so a date-range facet
 *   matches if any occurrence falls within range.
 */
function quant_search_node_dates($node, $date_fields) {
  $out = array();
  $fields = array_filter(array_map('trim', explode(',', (string) $date_fields)));
  foreach ($fields as $field_name) {
    $items = field_get_items('node', $node, $field_name);
    if (!$items) {
      continue;
    }
    $stamps = array();
    foreach ($items as $item) {
      // Date fields store ISO strings or timestamps in 'value'.
      if (isset($item['value'])) {
        $ts = is_numeric($item['value']) ? (int) $item['value'] : strtotime($item['value']);
        if ($ts) {
          $stamps[] = $ts;
        }
      }
    }
    if ($stamps) {
      $out[$field_name] = $stamps;
    }
  }
  return $out;
}
