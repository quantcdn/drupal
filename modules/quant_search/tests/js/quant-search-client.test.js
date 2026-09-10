// Run: node --test modules/quant_search/tests/js
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
