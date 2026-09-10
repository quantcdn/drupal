// Run: node --test modules/quant_search/tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');

const { Drupal } = require(path.join(__dirname, '..', '..', 'js', 'quant-search-client.js'));
const qs = Drupal.quantSearch;

test('facet equality, quoted and bare', () => {
  assert.equal(qs.toTypesenseFilter("content_type:'event'"), 'content_type:=`event`');
  assert.equal(qs.toTypesenseFilter('content_type:event'), 'content_type:=`event`');
  assert.equal(qs.toTypesenseFilter('event_type_en:"Kids & families"'), 'event_type_en:=`Kids & families`');
});

test('boolean combinators and parentheses', () => {
  assert.equal(qs.toTypesenseFilter("(content_type:'event') AND (lang_code:en OR lang_code:fr)"),
    '(content_type:=`event`) && (lang_code:=`en` || lang_code:=`fr`)');
  assert.equal(qs.toTypesenseFilter("NOT content_type:'page'"), 'content_type:!=`page`');
  assert.equal(qs.toTypesenseFilter('a:1 and b:2 or c:3'), 'a:=1 && b:=2 || c:=3');
});

test('numeric comparisons and ranges', () => {
  assert.equal(qs.toTypesenseFilter('field_event_session_start <= 5 AND field_event_session_end >= 1'),
    'field_event_session_start:<=5 && field_event_session_end:>=1');
  assert.equal(qs.toTypesenseFilter('timestamp:1 TO 9'), 'timestamp:[1..9]');
  assert.equal(qs.toTypesenseFilter('price != 5'), 'price:!=5');
});

test('empty and invalid input', () => {
  assert.equal(qs.toTypesenseFilter(''), '');
  assert.equal(qs.toTypesenseFilter(undefined), '');
  assert.throws(() => qs.toTypesenseFilter('a:`x`'));
  assert.throws(() => qs.toTypesenseFilter('(a:1'));
});

test('filtersFor picks the syntax by backend', () => {
  assert.equal(qs.filtersFor({ backend: 'algolia', filters: "content_type:'event'" }), "content_type:'event'");
  assert.equal(qs.filtersFor({ backend: 'typesense', filters: "content_type:'event'" }), 'content_type:=`event`');
  assert.equal(qs.filtersFor({ backend: 'typesense' }), '');
});

test('sortBy mirrors the server mapping', () => {
  assert.equal(qs.sortBy({ custom_ranking: ['asc(field_event_session_start)', 'asc(title)'] }),
    '_text_match:desc,field_event_session_start:asc,title:asc');
  assert.equal(qs.sortBy({ custom_ranking: ['asc(a)', 'desc(b)', 'asc(c)'] }), '_text_match:desc,a:asc,b:desc');
  assert.equal(qs.sortBy({ custom_ranking: ['garbage'] }), undefined);
  assert.equal(qs.sortBy({}), undefined);
});
