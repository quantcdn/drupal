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
  if (!quant_search_node_is_indexable($node)) {
    return FALSE;
  }

  $langcode = !empty($node->language) && $node->language != LANGUAGE_NONE ? $node->language : 'en';
  $record = array();

  // Token-driven fields.
  $data = array('node' => $node);
  $opts = array('clear' => TRUE, 'sanitize' => FALSE);
  foreach (array('title' => 'title_token', 'summary' => 'summary_token') as $key => $cfg) {
    if (!empty($config['node'][$cfg])) {
      $value = trim(token_replace($config['node'][$cfg], $data, $opts));
      if ($value !== '') {
        $record[$key] = decode_entities(strip_tags($value));
      }
    }
  }
  // Image: prefer image_field with entityref-follow; fall back to image_token.
  $image = quant_search_node_image_url($node, isset($config['node']['image_field']) ? $config['node']['image_field'] : '');
  if ($image === NULL && !empty($config['node']['image_token'])) {
    $image = trim(token_replace($config['node']['image_token'], $data, $opts));
  }
  if (!empty($image)) {
    $record['image'] = $image;
  }

  // Rendered body.
  $view_mode = !empty($config['node']['view_mode']) ? $config['node']['view_mode'] : 'full';
  $build = node_view($node, $view_mode);
  $record['content'] = decode_entities(trim(strip_tags(drupal_render($build))));

  // The search backend rejects records with an empty 'content' value. Some
  // content types (e.g. pages whose view mode renders no body text) produce
  // empty content; fall back to the summary, then the title, so every node
  // stays indexable.
  if ($record['content'] === '') {
    if (!empty($record['summary'])) {
      $record['content'] = $record['summary'];
    }
    elseif (!empty($record['title'])) {
      $record['content'] = $record['title'];
    }
    else {
      $record['content'] = $node->title;
    }
  }

  // Core attributes.
  $record['url'] = quant_search_node_url($node);
  $record['lang_code'] = $langcode;
  $record['content_type'] = $node->type;
  $type_info = node_type_get_type($node);
  $record['content_type_' . $langcode] = $type_info ? $type_info->name : $node->type;
  // Language name keyed by the node's language so a `language` facet works.
  $languages = language_list();
  if (isset($languages[$langcode])) {
    $record['language_' . $langcode] = $languages[$langcode]->name;
  }
  else {
    $record['language_' . $langcode] = $langcode;
  }

  // Taxonomy terms, grouped by vocabulary.
  $record += quant_search_node_terms($node, $langcode);

  // Date fields as numeric timestamps.
  $date_fields = isset($config['node']['date_fields']) ? $config['node']['date_fields'] : '';
  $record += quant_search_node_dates($node, $date_fields);

  // Index arbitrary configured fields.
  $extra = isset($config['node']['index_fields']) ? $config['node']['index_fields'] : '';
  $record += quant_search_node_extra_fields($node, $extra);

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
 *   matches if any occurrence falls within range. Date fields with an end
 *   date (value2) index both endpoints, so a range facet also matches
 *   events that are already underway (any value within range).
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
      // Date fields with an end date store it in 'value2'; index both
      // endpoints so range filters match events already underway.
      if (isset($item['value2']) && $item['value2'] !== $item['value']) {
        $ts = is_numeric($item['value2']) ? (int) $item['value2'] : strtotime($item['value2']);
        if ($ts) {
          $stamps[] = $ts;
        }
      }
    }
    if ($stamps) {
      $stamps = array_values(array_unique($stamps));
      sort($stamps);
      $out[$field_name] = $stamps;
    }
  }
  return $out;
}

/**
 * Queues a node to be pushed to the search index after the response.
 *
 * Mirrors the parent quant module's reactive-push pattern: the API call
 * runs in a shutdown function so content saves take no latency hit.
 */
function quant_search_index_node($node) {
  $pending = &drupal_static('quant_search_pending_nodes', array());
  $pending[$node->nid] = $node;
  drupal_register_shutdown_function('quant_search_index_shutdown');
}

/**
 * Shutdown callback: builds and sends records for queued nodes.
 */
function quant_search_index_shutdown() {
  $pending = &drupal_static('quant_search_pending_nodes', array());
  if (empty($pending)) {
    return;
  }
  $records = array();
  foreach ($pending as $node) {
    $record = quant_search_generate_record($node);
    if ($record) {
      $records[] = $record;
    }
  }
  $pending = array();
  if ($records) {
    quant_search_api_send_records($records);
  }
}

/**
 * Returns TRUE if the node's bundle is configured for indexing.
 *
 * Shared by the index and unindex paths so they agree on which nodes the
 * module manages in the search index.
 */
function quant_search_node_is_indexable($node) {
  $config = variable_get('quant_search_entities', array());
  return !empty($config['node']['enabled'])
    && !empty($config['node']['bundles'])
    && in_array($node->type, $config['node']['bundles'], TRUE);
}

/**
 * Returns the search-record URL for a node: '/' . path alias.
 *
 * This is both the value stored in a record's 'url' attribute and the key used
 * to remove the record from the index again, so indexing and unindexing stay
 * in lock-step.
 */
function quant_search_node_url($node) {
  $uri = entity_uri('node', $node);
  return '/' . drupal_get_path_alias($uri['path']);
}

/**
 * Queues a node for removal from the search index after the response.
 *
 * Mirrors quant_search_index_node(): the API call runs in a shutdown function
 * so the content operation takes no latency hit. The URL is captured now —
 * while the node and its alias are still available — and sent later.
 */
function quant_search_unindex_node($node) {
  if (!quant_search_node_is_indexable($node)) {
    return;
  }
  $url = quant_search_node_url($node);
  $pending = &drupal_static('quant_search_pending_deletes', array());
  $pending[$url] = $url;
  drupal_register_shutdown_function('quant_search_unindex_shutdown');
}

/**
 * Shutdown callback: removes queued URLs (all their chunks) from the index.
 */
function quant_search_unindex_shutdown() {
  $pending = &drupal_static('quant_search_pending_deletes', array());
  if (empty($pending)) {
    return;
  }
  $urls = $pending;
  $pending = array();
  foreach ($urls as $url) {
    quant_search_api_delete_record($url);
  }
}

/**
 * Returns a site-relative URL for a file URI (e.g. "/sites/default/files/foo.jpg").
 *
 * file_create_url() returns absolute URLs based on Drupal's bootstrapped
 * $base_url, which is incorrect in CLI/drush context (resolves to "default").
 * The browser fills in the correct host at render time.
 */
function quant_search_relative_file_url($uri) {
  $absolute = file_create_url($uri);
  $parts = parse_url($absolute);
  return isset($parts['path']) ? $parts['path'] : $absolute;
}

/**
 * Resolves an image URL for an entity given an image_field machine name.
 *
 * Supports direct image fields and entityreference fields (one level). Returns
 * NULL when no image can be resolved.
 */
function quant_search_node_image_url($node, $field_name) {
  if (!$field_name) {
    return NULL;
  }
  $items = field_get_items('node', $node, $field_name);
  if (!$items) {
    return NULL;
  }
  $first = reset($items);
  // Direct image field — has 'uri'.
  if (!empty($first['uri'])) {
    return quant_search_relative_file_url($first['uri']);
  }
  // File field (legacy) — has 'fid'.
  if (!empty($first['fid'])) {
    $file = file_load($first['fid']);
    return $file ? quant_search_relative_file_url($file->uri) : NULL;
  }
  // Entityreference / node_reference — follow one level.
  $target_id = isset($first['target_id']) ? $first['target_id']
             : (isset($first['nid']) ? $first['nid'] : NULL);
  if ($target_id) {
    // Determine entity type by inspecting the field's settings.
    $info = field_info_field($field_name);
    $target_type = 'node';
    if (!empty($info['settings']['target_type'])) {
      $target_type = $info['settings']['target_type'];
    }
    $target = entity_load_single($target_type, $target_id);
    if ($target) {
      $target_info = entity_get_info($target_type);
      $bundle_key = isset($target_info['entity keys']['bundle']) ? $target_info['entity keys']['bundle'] : '';
      $bundle = $bundle_key && isset($target->{$bundle_key}) ? $target->{$bundle_key} : NULL;
      // Walk the target's fields looking for an image-type field.
      foreach (field_info_instances($target_type, $bundle) as $tname => $instance) {
        $tinfo = field_info_field($tname);
        if (!empty($tinfo) && $tinfo['type'] === 'image') {
          $titems = field_get_items($target_type, $target, $tname);
          if ($titems) {
            $tfirst = reset($titems);
            if (!empty($tfirst['uri'])) {
              return quant_search_relative_file_url($tfirst['uri']);
            }
          }
        }
      }
    }
  }
  return NULL;
}

/**
 * Extracts arbitrary configured fields from a node into search-record keys.
 *
 * For each field machine name in $field_names (comma-separated), writes:
 *  - $record[$field_name] = single value or array of values (raw value)
 *  - $record[$field_name . '_label'] = the human-readable label when the
 *    field has allowed_values (list_text / list_integer / list_boolean) or
 *    is an entityreference / taxonomy_term_reference (the referenced label).
 */
function quant_search_node_extra_fields($node, $field_names) {
  $out = array();
  $names = array_filter(array_map('trim', explode(',', (string) $field_names)));
  foreach ($names as $name) {
    $info = field_info_field($name);
    if (!$info) {
      continue;
    }
    $items = field_get_items('node', $node, $name);
    if (!$items) {
      continue;
    }
    $values = array();
    $labels = array();
    foreach ($items as $item) {
      switch ($info['type']) {
        case 'list_text':
        case 'list_integer':
        case 'list_boolean':
          $v = isset($item['value']) ? $item['value'] : NULL;
          if ($v === NULL) { break; }
          $values[] = $v;
          $allowed = isset($info['settings']['allowed_values']) ? $info['settings']['allowed_values'] : array();
          if (isset($allowed[$v])) {
            $labels[] = trim(strip_tags((string) $allowed[$v]));
          }
          break;

        case 'taxonomy_term_reference':
          if (!empty($item['tid'])) {
            $values[] = (int) $item['tid'];
            $term = taxonomy_term_load($item['tid']);
            if ($term) { $labels[] = $term->name; }
          }
          break;

        case 'entityreference':
        case 'node_reference':
          $tid = isset($item['target_id']) ? $item['target_id']
               : (isset($item['nid']) ? $item['nid'] : NULL);
          if ($tid) {
            $values[] = (int) $tid;
            $tt = !empty($info['settings']['target_type']) ? $info['settings']['target_type'] : 'node';
            $t = entity_load_single($tt, $tid);
            if ($t) {
              $label = entity_label($tt, $t);
              if ($label !== FALSE) { $labels[] = $label; }
            }
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          $v = isset($item['value']) ? $item['value'] : NULL;
          if ($v === NULL || $v === '') { break; }
          $values[] = decode_entities(trim(strip_tags((string) $v)));
          break;

        default:
          if (isset($item['value'])) { $values[] = $item['value']; }
      }
    }
    if (!$values) {
      continue;
    }
    // Single-cardinality fields → scalar; multi → array.
    if (!empty($info['cardinality']) && $info['cardinality'] == 1) {
      $out[$name] = $values[0];
      if (isset($labels[0])) { $out[$name . '_label'] = $labels[0]; }
    }
    else {
      $out[$name] = $values;
      if ($labels) { $out[$name . '_label'] = $labels; }
    }
  }
  return $out;
}
