// Run: node --test modules/quant_search/tests/js/quant-search-client.test.js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');

const { Drupal } = require(path.join(__dirname, '..', '..', 'js', 'quant-search-client.js'));
const qs = Drupal.quantSearch;

test('sortBy mirrors the server mapping', () => {
  assert.equal(qs.sortBy({ custom_ranking: ['asc(field_event_session_start)', 'asc(title)'] }),
    '_text_match:desc,field_event_session_start:asc,title:asc');
  assert.equal(qs.sortBy({ custom_ranking: ['asc(a)', 'desc(b)', 'asc(c)'] }), '_text_match:desc,a:asc,b:desc');
  assert.equal(qs.sortBy({ custom_ranking: ['garbage'] }), undefined);
  assert.equal(qs.sortBy({}), undefined);
});

// A timestamp at local noon on the given day, so the day is the same in any zone.
const noon = (y, m, d) => Math.floor(new Date(y, m - 1, d, 12).getTime() / 1000);

test('timestampToDay gives the local YYYYMMDD day', () => {
  assert.equal(qs.timestampToDay(noon(2026, 9, 11)), 20260911);
  assert.equal(qs.timestampToDay(Math.floor(new Date(2026, 0, 1, 0, 0, 0).getTime() / 1000)), 20260101);
  assert.equal(qs.timestampToDay(Math.floor(new Date(2025, 11, 31, 23, 59, 59).getTime() / 1000)), 20251231);
});

test('scheduleClause for one day excludes unknown schedules', () => {
  const day = noon(2026, 9, 11);
  assert.equal(qs.scheduleClause({ backend: 'algolia' }, 'f', day, day),
    '(f_schedule = 1 OR f_days:20260911 TO 20260911)');
  assert.equal(qs.scheduleClause({ backend: 'typesense' }, 'f', day, day),
    '(f_schedule:=1 || f_days:[20260911..20260911])');
});

test('scheduleClause for several days includes unknown schedules', () => {
  const from = noon(2026, 9, 11);
  const to = noon(2026, 9, 18);
  assert.equal(qs.scheduleClause({ backend: 'algolia' }, 'f', from, to),
    '(f_schedule = 1 OR f_days:20260911 TO 20260918 OR f_schedule = 3)');
  assert.equal(qs.scheduleClause({ backend: 'typesense' }, 'f', from, to),
    '(f_schedule:=1 || f_days:[20260911..20260918] || f_schedule:=3)');
});

test('scheduleClause is empty for an open-ended range', () => {
  assert.equal(qs.scheduleClause({}, 'f', noon(2026, 9, 11), undefined), '');
  assert.equal(qs.scheduleClause({}, 'f', undefined, noon(2026, 9, 11)), '');
  assert.equal(qs.scheduleClause({}, 'f', undefined, undefined), '');
});

test('dateFilters joins the page filters and each active clause', () => {
  const day = noon(2026, 9, 11);
  const algolia = { backend: 'algolia', filters: '(content_type:event)' };
  assert.equal(qs.dateFilters(algolia, 'f', day, day),
    '(content_type:event) AND (f_schedule = 1 OR f_days:20260911 TO 20260911)');
  assert.equal(qs.dateFilters(algolia, 'f', day, undefined), '(content_type:event)');
  const typesense = { backend: 'typesense' };
  assert.equal(qs.dateFilters(typesense, 'f', day, day), '(f_schedule:=1 || f_days:[20260911..20260911])');
  assert.equal(qs.dateFilters(typesense, 'g', day, day),
    '(f_schedule:=1 || f_days:[20260911..20260911]) && (g_schedule:=1 || g_days:[20260911..20260911])');
});

test('syncDateFilters drops a clause once its refinements are cleared', () => {
  const day = noon(2026, 9, 11);
  const cfg = { backend: 'algolia', filters: 'x:1' };
  qs.dateFilters(cfg, 'f', day, day);
  const calls = [];
  const helper = {
    state: { numericRefinements: { f_start: { '<=': [day] } } },
    setQueryParameter: (k, v) => calls.push([k, v]),
    search: () => calls.push(['search'])
  };
  qs.syncDateFilters(helper, cfg, 'f');
  assert.deepEqual(calls, []);
  helper.state.numericRefinements = { f_start: { '<=': [] } };
  qs.syncDateFilters(helper, cfg, 'f');
  assert.deepEqual(calls, [['filters', 'x:1'], ['search']]);
  calls.length = 0;
  qs.syncDateFilters(helper, cfg, 'f');
  assert.deepEqual(calls, []);
});
