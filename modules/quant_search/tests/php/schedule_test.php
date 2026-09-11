<?php

/**
 * @file
 * Tests for the recurrence parser and day expander in quant_search.schedule.inc.
 *
 * Plain PHP, no Drupal bootstrap. Run: php modules/quant_search/tests/php/schedule_test.php
 */

require __DIR__ . '/../../quant_search.schedule.inc';

$failures = 0;
function check($label, $actual, $expected) {
  global $failures;
  if ($actual === $expected) {
    print "ok   $label\n";
    return;
  }
  $failures++;
  print "FAIL $label\n     expected " . var_export($expected, TRUE) . "\n     actual   " . var_export($actual, TRUE) . "\n";
}

$tz = new DateTimeZone('Australia/Melbourne');
$ts = function ($local) use ($tz) {
  return (new DateTime($local, $tz))->getTimestamp();
};

// Parser: rules that must be recognised.
check('Auslan teaser: 1st and 3rd Monday',
  quant_search_schedule_parse('Come along every 1st and 3rd Monday of each month at State Library Victoria as we welcome Deaf storytellers.'),
  array('weekdays' => array(1), 'ordinals' => array(1, 3)));
check('every Tuesday', quant_search_schedule_parse('Drop in every Tuesday.'), array('weekdays' => array(2), 'ordinals' => array()));
check('every Saturday and Sunday', quant_search_schedule_parse('Open every Saturday and Sunday'), array('weekdays' => array(6, 7), 'ordinals' => array()));
check('every weekend', quant_search_schedule_parse('Runs every weekend.'), array('weekdays' => array(6, 7), 'ordinals' => array()));
check('every weekday', quant_search_schedule_parse('every weekday from 9am'), array('weekdays' => array(1, 2, 3, 4, 5), 'ordinals' => array()));
check('every day', quant_search_schedule_parse('Open every day'), array('weekdays' => array(1, 2, 3, 4, 5, 6, 7), 'ordinals' => array()));
check('every last Friday of the month', quant_search_schedule_parse('On every last Friday of the month'), array('weekdays' => array(5), 'ordinals' => array(-1)));
check('each first Sunday', quant_search_schedule_parse('each first Sunday'), array('weekdays' => array(7), 'ordinals' => array(1)));
check('the same rule twice is still one rule', quant_search_schedule_parse('Every Monday. See you every Monday!'), array('weekdays' => array(1), 'ordinals' => array()));

// Parser: text that must NOT be guessed.
check('no schedule in text', quant_search_schedule_parse('Create a keepsake at a free drop-in craft session.'), NULL);
check('every other week is ambiguous', quant_search_schedule_parse('every other Tuesday'), NULL);
check('fortnightly is ambiguous', quant_search_schedule_parse('Fortnightly on Tuesdays, every Tuesday in term'), NULL);
check('exceptions are not modelled', quant_search_schedule_parse('every Tuesday except public holidays'), NULL);
check('two different rules conflict', quant_search_schedule_parse('Kids every Monday. Adults every Friday.'), NULL);
check('empty text', quant_search_schedule_parse(''), NULL);

// Expander: Auslan Storytime's real date range, in Melbourne time.
$auslan = quant_search_schedule_expand(array('weekdays' => array(1), 'ordinals' => array(1, 3)),
  $ts('2026-02-02 12:30'), $ts('2026-12-07 13:15'), $tz, 400);
check('Auslan: first occurrence', reset($auslan), 20260202);
check('Auslan: last occurrence is 7 Dec, not the 3rd Monday after the range', end($auslan), 20261207);
check('Auslan: 21 occurrences, Feb to Nov twice a month plus 7 Dec', count($auslan), 21);
check('Auslan: on Mon 7 Sep (1st Monday)', in_array(20260907, $auslan, TRUE), TRUE);
check('Auslan: on Mon 21 Sep (3rd Monday)', in_array(20260921, $auslan, TRUE), TRUE);
check('Auslan: not on Mon 14 Sep (2nd Monday)', in_array(20260914, $auslan, TRUE), FALSE);
check('Auslan: not on Fri 11 Sep', in_array(20260911, $auslan, TRUE), FALSE);

// Expander: last weekday of the month.
check('last Friday of September 2026 is the 25th',
  quant_search_schedule_expand(array('weekdays' => array(5), 'ordinals' => array(-1)), $ts('2026-09-01 00:00'), $ts('2026-09-30 23:00'), $tz, 400),
  array(20260925));

// Every day of a short range, and Melbourne day boundaries.
check('every day of a three-day range',
  quant_search_schedule_all_days($ts('2026-09-08 17:30'), $ts('2026-09-10 19:00'), $tz, 400),
  array(20260908, 20260909, 20260910));
check('11:30pm Melbourne stays on its own day, not the UTC day',
  quant_search_schedule_all_days($ts('2026-09-10 23:30'), $ts('2026-09-10 23:45'), $tz, 400), array(20260910));
check('12:30am Melbourne is the next day even though UTC is still the day before',
  quant_search_schedule_all_days($ts('2026-09-11 00:30'), $ts('2026-09-11 01:00'), $tz, 400), array(20260911));

// The cap bounds the output for very long ranges.
check('cap limits the number of days',
  count(quant_search_schedule_all_days($ts('2026-01-01 00:00'), $ts('2030-12-31 00:00'), $tz, 400)), 400);

print $failures ? "\n$failures FAILED\n" : "\nall passed\n";
exit($failures ? 1 : 0);
