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
  $record += quant_search_node_dates($node, $date_fields, isset($config['node']) ? $config['node'] : array());

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
 * @param array $config
 *   The node entity settings (daily_field, schedule_fields).
 *
 * @return array
 *   Attributes per date field that has a value:
 *   - {field_name}: array of integer timestamps. Multi-value and recurring
 *     date fields yield multiple timestamps, and a date field with an end
 *     date (value2) contributes both endpoints. Renderers use this list to
 *     show the sessions that fall inside the active date filter.
 *   - {field_name}_start: the earliest start timestamp.
 *   - {field_name}_end: the latest end timestamp (falls back to the start
 *     when the field has no end date).
 *   - {field_name}_schedule: 1 daily, 2 on listed days, 3 unknown days.
 *   - {field_name}_days: YYYYMMDD integers the event is on from today, or
 *     array(0) when there are none.
 *   A date filter uses the scalar pair as an overlap test
 *   (start <= filter end AND end >= filter start), so an exhibition that
 *   opened last year and closes next year still matches "today". A bounded
 *   range then also requires the event to be daily, or on a listed day, or,
 *   for ranges longer than one day, of unknown schedule.
 */
function quant_search_node_dates($node, $date_fields, array $config = array()) {
  $out = array();
  foreach (array_filter(array_map('trim', explode(',', (string) $date_fields))) as $field_name) {
    $ranges = quant_search_node_date_ranges($node, $field_name);
    if (!$ranges) {
      continue;
    }
    $stamps = array();
    foreach ($ranges as $range) {
      $stamps[] = $range[0];
      $stamps[] = $range[1];
    }
    $stamps = array_values(array_unique($stamps));
    sort($stamps);
    $out[$field_name] = $stamps;
    $out[$field_name . '_start'] = min(array_column($ranges, 0));
    $out[$field_name . '_end'] = max(array_column($ranges, 1));
    list($schedule, $days) = quant_search_node_schedule($node, $ranges, $config);
    $out[$field_name . '_schedule'] = $schedule;
    // Never empty: Typesense declares a field from its first non-empty
    // numeric value, and a filter on an undeclared field fails the query.
    // 0 is no calendar day, so it never matches a range.
    $out[$field_name . '_days'] = $days ? $days : array(0);
  }
  return $out;
}

/**
 * Returns the [start, end] timestamp pairs of a node's date field items.
 */
function quant_search_node_date_ranges($node, $field_name) {
  $ranges = array();
  foreach ((array) field_get_items('node', $node, $field_name) as $item) {
    $range = is_array($item) ? quant_search_date_item_range($item) : NULL;
    if ($range) {
      $ranges[] = $range;
    }
  }
  return $ranges;
}

/**
 * Longest single date item, in seconds, still read as "on every day of it".
 *
 * A one-off event or a weekend or week-long program is on each of its days.
 * Anything longer that is not daily is almost always a schedule summary
 * ("Feb to Dec") whose real days live in the text or nowhere.
 */
define('QUANT_SEARCH_SCHEDULE_SHORT_SPAN', 7 * 86400);

/**
 * Most days written to {field}_days, so no record grows unbounded.
 */
define('QUANT_SEARCH_SCHEDULE_MAX_DAYS', 400);

/**
 * Returns the schedule type and the days a node is on.
 *
 * A daily event (the configured flag is set) is on every day of its range.
 * Otherwise a short date item is on each of its days, and a long item is on
 * the days of the rule its text states ("every Tuesday", "first and third
 * Monday"). When a long item has no rule, the schedule is unknown. Days
 * before today are dropped, and days are listed at most two years ahead,
 * because every date filter looks forward from today.
 *
 * @return array
 *   array(schedule type, YYYYMMDD integers).
 */
function quant_search_node_schedule($node, array $ranges, array $config) {
  module_load_include('inc', 'quant_search', 'quant_search.schedule');
  if (quant_search_node_is_daily($node, $config)) {
    return array(QUANT_SEARCH_SCHEDULE_DAILY, array());
  }
  $tz = new DateTimeZone(variable_get('date_default_timezone', date_default_timezone_get()));
  $long = array_filter($ranges, 'quant_search_range_is_long');
  $days = quant_search_ranges_days(array_diff_key($ranges, $long), $tz);
  if (!$long) {
    return array(QUANT_SEARCH_SCHEDULE_DAYS, $days);
  }
  $rule = quant_search_schedule_parse(quant_search_node_schedule_text($node, $config));
  if (!$rule) {
    return array(QUANT_SEARCH_SCHEDULE_UNKNOWN, array());
  }
  if (count($rule['weekdays']) === 7 && !$rule['ordinals']) {
    return array(QUANT_SEARCH_SCHEDULE_DAILY, array());
  }
  foreach ($long as $range) {
    $clamped = quant_search_schedule_clamp($range, $tz);
    if ($clamped) {
      $days = array_merge($days, quant_search_schedule_expand($rule, $clamped[0], $clamped[1], $tz, QUANT_SEARCH_SCHEDULE_MAX_DAYS));
    }
  }
  return array(QUANT_SEARCH_SCHEDULE_DAYS, array_slice(quant_search_schedule_unique($days), 0, QUANT_SEARCH_SCHEDULE_MAX_DAYS));
}

/**
 * TRUE when a date item is longer than QUANT_SEARCH_SCHEDULE_SHORT_SPAN.
 */
function quant_search_range_is_long(array $range) {
  return $range[1] - $range[0] > QUANT_SEARCH_SCHEDULE_SHORT_SPAN;
}

/**
 * Returns every day covered by a list of ranges, from today onwards.
 */
function quant_search_ranges_days(array $ranges, DateTimeZone $tz) {
  $days = array();
  foreach ($ranges as $range) {
    $clamped = quant_search_schedule_clamp($range, $tz);
    if ($clamped) {
      $days = array_merge($days, quant_search_schedule_all_days($clamped[0], $clamped[1], $tz, QUANT_SEARCH_SCHEDULE_MAX_DAYS));
    }
  }
  return array_slice(quant_search_schedule_unique($days), 0, QUANT_SEARCH_SCHEDULE_MAX_DAYS);
}

/**
 * Clamps a range to [today, today + 2 years], or NULL when nothing is left.
 */
function quant_search_schedule_clamp(array $range, DateTimeZone $tz) {
  $today = new DateTime('today', $tz);
  $until = clone $today;
  $until->modify('+2 years');
  $start = max($range[0], $today->getTimestamp());
  $end = min($range[1], $until->getTimestamp());
  return $start <= $end ? array($start, $end) : NULL;
}

/**
 * TRUE when the configured daily flag field is set on a node.
 *
 * A term-based rule is deliberately absent: sites tag talks and tours with
 * an "Exhibitions" event type, so a term does not mean "open every day".
 */
function quant_search_node_is_daily($node, array $config) {
  if (empty($config['daily_field'])) {
    return FALSE;
  }
  $items = field_get_items('node', $node, $config['daily_field']);
  return $items && !empty($items[0]['value']);
}

/**
 * Returns the text of the configured schedule fields, for rule parsing.
 */
function quant_search_node_schedule_text($node, array $config) {
  $text = '';
  foreach (quant_search_split_list(isset($config['schedule_fields']) ? $config['schedule_fields'] : '') as $field) {
    foreach ((array) field_get_items('node', $node, $field) as $item) {
      $text .= ' ' . (isset($item['value']) ? $item['value'] : '');
    }
  }
  // A non-breaking space between "every" and the weekday must still match.
  return str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
}

/**
 * Splits a comma-separated setting into trimmed, non-empty values.
 */
function quant_search_split_list($value) {
  return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
}

/**
 * Returns the [start, end] timestamps of one date field item.
 *
 * Date fields store ISO strings or timestamps in 'value' and, for fields
 * with an end date, in 'value2'. The end falls back to the start when the
 * item has no usable end date.
 *
 * @param array $item
 *   A single date field item.
 *
 * @return array|null
 *   Array of two integer timestamps, or NULL when the item has no start.
 */
function quant_search_date_item_range(array $item) {
  $start = isset($item['value']) ? quant_search_date_to_timestamp($item['value']) : 0;
  if (!$start) {
    return NULL;
  }
  $end = isset($item['value2']) ? quant_search_date_to_timestamp($item['value2']) : 0;
  return array($start, $end ? $end : $start);
}

/**
 * Converts a date field value (ISO string or numeric) to a Unix timestamp.
 *
 * @param mixed $value
 *   The raw field value.
 *
 * @return int
 *   The timestamp, or 0 when the value cannot be parsed.
 */
function quant_search_date_to_timestamp($value) {
  if (is_numeric($value)) {
    return (int) $value;
  }
  $ts = strtotime((string) $value);
  return $ts ? $ts : 0;
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
