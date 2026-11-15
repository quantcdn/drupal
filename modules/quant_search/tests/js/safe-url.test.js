/**
 * @file
 * Search result URLs: only http, https and relative URLs are links.
 *
 * Run with: node --test modules/quant_search/tests/js/*.test.js
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const jsDir = path.join(__dirname, '..', '..', 'js');

const UNSAFE = [
  'javascript:alert(1)',
  'JavaScript:alert(1)',
  ' javascript:alert(1)',
  'java\tscript:alert(1)',
  'java\nscript:alert(1)',
  'data:text/html,<script>alert(1)</script>',
  'vbscript:msgbox(1)',
  'file:///etc/passwd',
  'http://[invalid',
  '',
  '   ',
  null,
  undefined,
  42,
  {},
];

const SAFE = [
  'http://example.com/page',
  'https://example.com/page?a=1#b',
  'HTTPS://example.com/',
  '/about-us',
  'about-us',
  '../about-us',
  '?keys=test',
  '#section',
  '//example.com/page',
];

/**
 * A sandbox with the browser globals the search scripts use.
 */
function sandbox(extra) {
  const calls = { assign: [], open: [] };
  const window = {
    location: {
      origin: 'https://site.example',
      assign: (url) => calls.assign.push(url),
    },
    open: (...args) => {
      calls.open.push(args);
      return null;
    },
  };
  const context = Object.assign({
    window,
    URL,
    Drupal: { behaviors: {}, t: (s) => s },
    drupalSettings: {},
    once: (id, selector) => [selector],
    getComputedStyle: () => ({ getPropertyValue: () => '' }),
    document: { querySelector: () => null },
    algoliasearch: () => ({}),
  }, extra || {});
  context.window.document = context.document;
  vm.createContext(context);
  vm.runInContext(fs.readFileSync(path.join(jsDir, 'quant-search-client.js'), 'utf8'), context);
  return { context, calls };
}

function run(context, file) {
  vm.runInContext(fs.readFileSync(path.join(jsDir, file), 'utf8'), context);
}

test('safeUrl rejects other schemes and unparseable URLs', () => {
  const { context } = sandbox();
  for (const url of UNSAFE) {
    assert.strictEqual(context.Drupal.quantSearch.safeUrl(url), null, String(url));
  }
});

test('safeUrl keeps http, https and relative URLs unchanged', () => {
  const { context } = sandbox();
  for (const url of SAFE) {
    assert.strictEqual(context.Drupal.quantSearch.safeUrl(url), url, url);
  }
});

/**
 * The options quant-autocomplete.js gives to autocomplete().
 */
function autocompleteOptions() {
  let options;
  const { context, calls } = sandbox();
  context.window['@algolia/autocomplete-js'] = {
    autocomplete: (opts) => {
      options = opts;
    },
    getAlgoliaResults: () => [],
  };
  context.drupalSettings.quantSearchAutocomplete = { index: 'idx', search_path: '/search' };
  run(context, 'quant-autocomplete.js');
  context.Drupal.behaviors.autocompleteBlockInit.attach(context.document, {});
  return { options, source: options.getSources()[0], calls };
}

test('autocomplete does not open an unsafe result URL', () => {
  const { options, source, calls } = autocompleteOptions();
  for (const url of UNSAFE) {
    assert.strictEqual(source.getItemUrl({ item: { url } }), undefined, String(url));
    options.navigator.navigate({ itemUrl: url });
    options.navigator.navigateNewTab({ itemUrl: url });
    options.navigator.navigateNewWindow({ itemUrl: url });
    source.onSelect({ item: { url } });
  }
  assert.deepStrictEqual(calls.assign, []);
  assert.deepStrictEqual(calls.open, []);
});

test('autocomplete opens http, https and relative result URLs', () => {
  const { options, source, calls } = autocompleteOptions();
  for (const url of SAFE) {
    assert.strictEqual(source.getItemUrl({ item: { url } }), url);
    options.navigator.navigate({ itemUrl: url });
    options.navigator.navigateNewTab({ itemUrl: url });
    options.navigator.navigateNewWindow({ itemUrl: url });
    source.onSelect({ item: { url } });
  }
  assert.deepStrictEqual(calls.assign, SAFE.flatMap((url) => [url, url]));
  assert.deepStrictEqual(calls.open, SAFE.flatMap((url) => [
    [url, '_blank', 'noopener'],
    [url, '_blank', 'noopener'],
  ]));
});

test('search page results link only to safe URLs', () => {
  const widgets = [];
  const widget = (name) => (opts) => ({ name, opts });
  const instantsearch = () => ({
    templatesConfig: { helpers: {} },
    addWidgets: (list) => widgets.push(...list),
    start: () => {},
  });
  instantsearch.widgets = new Proxy({}, { get: (target, name) => widget(name) });
  const { context } = sandbox({ instantsearch });
  context.drupalSettings.quantSearch = {
    index: 'idx',
    facets: [],
    display: { results: {}, pagination: {} },
  };
  run(context, 'quant-search.js');
  context.Drupal.behaviors.searchPageInit.attach(context.document, {});

  const hits = widgets.find((w) => w.name === 'hits').opts;
  const items = UNSAFE.concat(SAFE).map((url, i) => ({ objectID: String(i), url, title: 't' }));
  const out = hits.transformItems(items);
  assert.deepStrictEqual(out.map((item) => item.url), UNSAFE.map(() => null).concat(SAFE));
  assert.deepStrictEqual(out.map((item) => item.objectID), items.map((item) => item.objectID));
  assert.match(hits.templates.item, /<a\{\{#url\}\} href="\{\{url\}\}"\{\{\/url\}\}>/);
});
