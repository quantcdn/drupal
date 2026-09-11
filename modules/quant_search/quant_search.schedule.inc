<?php

/**
 * @file
 * Event schedules: read a recurrence rule written in an event's text, and
 * expand date ranges into the days an event is actually on.
 *
 * Pure PHP with no Drupal calls, so tests/php/schedule_test.php runs it
 * without a bootstrap. Days are integers in YYYYMMDD form in the timezone
 * passed in: they compare correctly in a numeric range filter, and they are
 * the same dates a visitor picks in a date input.
 */

/**
 * Schedule types written to {field}_schedule.
 *
 * DAILY:   on every day of its date range.
 * DAYS:    on the days listed in {field}_days.
 * UNKNOWN: runs across its date range, on days the content does not state.
 */
define('QUANT_SEARCH_SCHEDULE_DAILY', 1);
define('QUANT_SEARCH_SCHEDULE_DAYS', 2);
define('QUANT_SEARCH_SCHEDULE_UNKNOWN', 3);

/**
 * Wording that makes a written schedule too ambiguous to expand safely.
 */
define('QUANT_SEARCH_SCHEDULE_AMBIGUOUS', '/\b(every other|fortnight\w*|alternate|except|excluding|other than)\b/');

/**
 * Returns the recurrence rule stated in a piece of text, or NULL.
 *
 * Recognises "every"/"each" followed by weekdays, optionally preceded by
 * ordinals: "every Tuesday", "every Saturday and Sunday", "every weekend",
 * "every 1st and 3rd Monday of each month", "every last Friday". Returns
 * NULL when nothing matches, when the text is ambiguous (every other week,
 * fortnightly, exceptions), or when it states more than one different rule:
 * a wrong schedule is worse than an unknown one.
 *
 * @param string $text
 *   Plain or HTML text.
 *
 * @return array|null
 *   array('weekdays' => ISO weekday numbers 1-7, 'ordinals' => week numbers
 *   within the month, -1 for last; empty means every week).
 */
function quant_search_schedule_parse($text) {
  $text = strtolower(trim(strip_tags((string) $text)));
  if ($text === '' || preg_match(QUANT_SEARCH_SCHEDULE_AMBIGUOUS, $text)) {
    return NULL;
  }
  $rules = array();
  foreach (quant_search_schedule_phrases($text) as $match) {
    $weekdays = quant_search_schedule_weekdays($match[2]);
    if ($weekdays) {
      $rule = array('weekdays' => $weekdays, 'ordinals' => quant_search_schedule_ordinals($match[1]));
      $rules[json_encode($rule)] = $rule;
    }
  }
  return count($rules) === 1 ? reset($rules) : NULL;
}

/**
 * Returns every "every/each [ordinals] weekdays" phrase in lower-case text.
 *
 * @return array
 *   preg_match_all() sets: [0] phrase, [1] ordinals text, [2] weekdays text.
 */
function quant_search_schedule_phrases($text) {
  $ordinal = '(?:1st|2nd|3rd|4th|first|second|third|fourth|last)';
  $day = '(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|weekday|weekend|day)s?';
  $sep = '\s*(?:,|and|&|or)\s*';
  preg_match_all("/\\b(?:every|each)\\s+((?:$ordinal(?:$sep)?)*)\\s*($day(?:$sep$day)*)\\b/", $text, $matches, PREG_SET_ORDER);
  return $matches;
}

/**
 * Returns the sorted ISO weekday numbers named in a weekdays phrase.
 */
function quant_search_schedule_weekdays($text) {
  $map = array(
    'monday' => array(1), 'tuesday' => array(2), 'wednesday' => array(3),
    'thursday' => array(4), 'friday' => array(5), 'saturday' => array(6),
    'sunday' => array(7), 'weekday' => range(1, 5), 'weekend' => array(6, 7),
    'day' => range(1, 7),
  );
  preg_match_all('/(monday|tuesday|wednesday|thursday|friday|saturday|sunday|weekday|weekend|day)s?/', $text, $words);
  $days = array();
  foreach ($words[1] as $word) {
    $days = array_merge($days, $map[$word]);
  }
  return quant_search_schedule_unique($days);
}

/**
 * Returns the sorted week-of-month numbers in an ordinals phrase, -1 = last.
 */
function quant_search_schedule_ordinals($text) {
  $map = array(
    '1st' => 1, 'first' => 1, '2nd' => 2, 'second' => 2,
    '3rd' => 3, 'third' => 3, '4th' => 4, 'fourth' => 4, 'last' => -1,
  );
  preg_match_all('/1st|2nd|3rd|4th|first|second|third|fourth|last/', $text, $words);
  $ordinals = array();
  foreach ($words[0] as $word) {
    $ordinals[] = $map[$word];
  }
  return quant_search_schedule_unique($ordinals);
}

/**
 * Returns the unique values of a list of integers, sorted ascending.
 */
function quant_search_schedule_unique(array $values) {
  $values = array_values(array_unique($values));
  sort($values);
  return $values;
}

/**
 * Returns the days between two timestamps that match a rule.
 *
 * @param array $rule
 *   As returned by quant_search_schedule_parse().
 * @param int $start_ts
 *   Range start. Its local date is the first day considered.
 * @param int $end_ts
 *   Range end. Its local date is the last day considered.
 * @param DateTimeZone $tz
 *   The timezone that decides which date a timestamp falls on.
 * @param int $cap
 *   The most days to return, so a long range cannot bloat a search record.
 *
 * @return int[]
 *   YYYYMMDD integers in ascending order.
 */
function quant_search_schedule_expand(array $rule, $start_ts, $end_ts, DateTimeZone $tz, $cap) {
  $days = array();
  $day = quant_search_schedule_local_midnight($start_ts, $tz);
  $last = quant_search_schedule_local_midnight($end_ts, $tz);
  while ($day <= $last && count($days) < $cap) {
    if (quant_search_schedule_matches($rule, $day)) {
      $days[] = (int) $day->format('Ymd');
    }
    $day->modify('+1 day');
  }
  return $days;
}

/**
 * Returns every day between two timestamps. See quant_search_schedule_expand().
 */
function quant_search_schedule_all_days($start_ts, $end_ts, DateTimeZone $tz, $cap) {
  return quant_search_schedule_expand(array('weekdays' => range(1, 7), 'ordinals' => array()), $start_ts, $end_ts, $tz, $cap);
}

/**
 * Returns midnight, local time, on the date a timestamp falls on.
 */
function quant_search_schedule_local_midnight($ts, DateTimeZone $tz) {
  $day = new DateTime('@' . (int) $ts);
  $day->setTimezone($tz);
  $day->setTime(0, 0, 0);
  return $day;
}

/**
 * TRUE when a day satisfies a rule.
 */
function quant_search_schedule_matches(array $rule, DateTime $day) {
  if (!in_array((int) $day->format('N'), $rule['weekdays'], TRUE)) {
    return FALSE;
  }
  if (!$rule['ordinals']) {
    return TRUE;
  }
  $week = (int) ceil((int) $day->format('j') / 7);
  $is_last = (int) $day->format('j') + 7 > (int) $day->format('t');
  return in_array($week, $rule['ordinals'], TRUE) || ($is_last && in_array(-1, $rule['ordinals'], TRUE));
}
