/**
 * @file
 * Quant Search — InstantSearch.js initialisation.
 *
 * Renders one InstantSearch instance per search page in
 * Drupal.settings.quantSearch. Container ids are prefixed "qs-{instance}-".
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.quantSearch = {
    attach: function (context, settings) {
      if (!settings.quantSearch || typeof instantsearch === 'undefined') {
        return;
      }
      Object.keys(settings.quantSearch).forEach(function (instance) {
        var cfg = settings.quantSearch[instance];
        var root = document.getElementById('quant-search-' + instance);
        if (!root || root.getAttribute('data-qs-init')) {
          return;
        }
        root.setAttribute('data-qs-init', '1');
        Drupal.quantSearch.build(cfg);
      });
    }
  };

  Drupal.quantSearch = Drupal.quantSearch || {};

  /**
   * Returns a URL that is safe to put in an href/src, or '#' otherwise.
   * Accepts site-relative paths and http/https absolute URLs only.
   */
  Drupal.quantSearch.safeUrl = function (url) {
    if (typeof url !== 'string' || url === '') {
      return '#';
    }
    if (url.charAt(0) === '/' || /^https?:\/\//i.test(url)) {
      return Drupal.checkPlain(url);
    }
    return '#';
  };

  /**
   * Returns HTML annotating an event hit with its session count and earliest
   * in-range date. Returns '' when no date attribute applies.
   *
   * @param {object} hit
   * @param {array} dateFacetKeys
   *   Attribute names that hold timestamp arrays for this search page.
   * @param {object} refinements
   *   Map of attribute name -> {min, max} (in Unix seconds), reflecting the
   *   active date-range refinement, or empty when no range is applied.
   */
  Drupal.quantSearch.formatSessionInfo = function (hit, dateFacetKeys, refinements) {
    if (!dateFacetKeys || !dateFacetKeys.length) { return ''; }
    for (var i = 0; i < dateFacetKeys.length; i++) {
      var key = dateFacetKeys[i];
      var values = hit && hit[key];
      if (!values) { continue; }
      if (!Array.isArray(values)) { values = [values]; }
      var ref = refinements[key];
      var inRange = values.filter(function (ts) {
        if (typeof ts !== 'number') { return false; }
        if (ref && ref.min !== undefined && ts < ref.min) { return false; }
        if (ref && ref.max !== undefined && ts > ref.max) { return false; }
        return true;
      });
      if (!inRange.length) { continue; }
      inRange.sort(function (a, b) { return a - b; });
      var label;
      if (inRange.length === 1) {
        label = Drupal.t('1 session');
      }
      else {
        label = Drupal.t('@n sessions', { '@n': inRange.length });
      }
      var first = new Date(inRange[0] * 1000);
      var dateText = first.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
      var nextWord = ref ? Drupal.t('from') : Drupal.t('next');
      return '<div class="qs-hit-sessions"><strong>' + label + '</strong> · ' +
        nextWord + ' ' + Drupal.checkPlain(dateText) + '</div>';
    }
    return '';
  };

  /**
   * Builds and starts one InstantSearch instance.
   */
  Drupal.quantSearch.build = function (cfg) {
    var id = function (suffix) { return '#qs-' + cfg.instance + '-' + suffix; };

    // Apply initial layout (admin default, overridable by the visitor's last choice).
    var storageKey = 'qs-layout-' + cfg.instance;
    var initialLayout = (function () {
      try { return window.localStorage.getItem(storageKey); } catch (e) { return null; }
    }()) || (cfg.display && cfg.display.layout) || 'card';
    Drupal.quantSearch.applyLayout(cfg.instance, initialLayout);
    Drupal.quantSearch.installLayoutToolbar(cfg.instance, initialLayout);

    var search = instantsearch({
      indexName: cfg.index,
      searchClient: algoliasearch(cfg.app_id, cfg.read_key),
      routing: false
    });

    var widgets = [];
    var display = cfg.display || {};
    var results = display.results || {};
    var pagination = display.pagination || {};

    if (results.display_search) {
      widgets.push(instantsearch.widgets.searchBox({ container: id('searchbox') }));
    }
    if (results.display_stats) {
      widgets.push(instantsearch.widgets.stats({ container: id('stats') }));
    }
    if (pagination.pagination_enabled) {
      widgets.push(instantsearch.widgets.pagination({ container: id('pagination') }));
    }
    if (results.show_clear_refinements) {
      widgets.push(instantsearch.widgets.clearRefinements({ container: id('clear') }));
    }

    // Track active date-range refinements for use in the hits template.
    var dateRefinements = {};
    var dateFacetKeys = (cfg.facets || []).filter(function (f) {
      return f.widget === 'date' || f.type === 'date_range';
    }).map(function (f) { return f.facet_key; });

    // Facets.
    (cfg.facets || []).forEach(function (facet) {
      var container = id('facet-' + facet.facet_container);
      switch (facet.widget) {
        case 'checkbox':
          widgets.push(instantsearch.widgets.refinementList({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;

        case 'select':
          widgets.push(instantsearch.widgets.menuSelect({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;

        case 'menu':
          widgets.push(instantsearch.widgets.menu({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;

        case 'date':
          (function (key) {
            widgets.push(Drupal.quantSearch.dateRangeWidget(container, key, function (min, max) {
              if (min === undefined && max === undefined) {
                delete dateRefinements[key];
              } else {
                dateRefinements[key] = { min: min, max: max };
              }
            }));
          }(facet.facet_key));
          break;
      }
    });

    widgets.push(instantsearch.widgets.configure({
      filters: cfg.filters || '',
      hitsPerPage: pagination.per_page || 20,
      attributesToSnippet: ['summary:50']
    }));

    widgets.push(instantsearch.widgets.hits({
      container: id('hits'),
      templates: {
        empty: '<p>' + Drupal.t('No results found.') + '</p>',
        item: function (hit) {
          var url = Drupal.quantSearch.safeUrl(hit.url);
          var image = Drupal.quantSearch.safeUrl(hit.image);
          var img = image ? '<img src="' + image + '" alt="" class="qs-hit-image" />' : '';
          var summary = hit.summary
            ? '<div class="qs-hit-summary">' + Drupal.checkPlain(hit.summary) + '</div>' : '';
          var sessions = Drupal.quantSearch.formatSessionInfo(hit, dateFacetKeys, dateRefinements);
          return '<a class="qs-hit" href="' + url + '">' + img +
            '<h4 class="qs-hit-title">' + Drupal.checkPlain(hit.title || '') + '</h4>' +
            sessions + summary + '</a>';
        }
      }
    }));

    search.addWidgets(widgets);
    search.start();
  };

  /**
   * Sets the active layout class on the wrapper and reflects it on the toolbar.
   */
  Drupal.quantSearch.applyLayout = function (instance, layout) {
    var root = document.getElementById('quant-search-' + instance);
    if (!root) { return; }
    root.classList.remove('quant-search--layout-card', 'quant-search--layout-list');
    root.classList.add('quant-search--layout-' + layout);
    var buttons = root.querySelectorAll('.quant-search-toolbar button[data-qs-layout]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].classList.toggle('is-active', buttons[i].getAttribute('data-qs-layout') === layout);
    }
  };

  /**
   * Inserts a Cards/List toggle above the results, persisting the choice in
   * localStorage keyed by instance.
   */
  Drupal.quantSearch.installLayoutToolbar = function (instance, initial) {
    var root = document.getElementById('quant-search-' + instance);
    if (!root || root.querySelector('.quant-search-toolbar')) { return; }
    var results = root.querySelector('.quant-search-results');
    if (!results) { return; }
    var bar = document.createElement('div');
    bar.className = 'quant-search-toolbar';
    bar.innerHTML =
      '<button type="button" data-qs-layout="card">' + Drupal.t('Cards') + '</button>' +
      '<button type="button" data-qs-layout="list">' + Drupal.t('List') + '</button>';
    bar.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('button[data-qs-layout]');
      if (!btn) { return; }
      var layout = btn.getAttribute('data-qs-layout');
      try { window.localStorage.setItem('qs-layout-' + instance, layout); } catch (err) {}
      Drupal.quantSearch.applyLayout(instance, layout);
    });
    results.insertBefore(bar, results.firstChild);
  };

  /**
   * A date-range facet widget built on connectRange + native date inputs.
   *
   * The indexed attribute is a numeric Unix timestamp (or array of them for
   * recurring dates); the search backend matches a numeric range against any
   * value in the array.
   */
  Drupal.quantSearch.dateRangeWidget = function (container, attribute, onChange) {
    var render = function (renderOptions, isFirstRender) {
      var node = document.querySelector(container);
      if (!node) { return; }
      if (isFirstRender) {
        node.innerHTML =
          '<div class="quant-search-date-range">' +
          '<label>' + Drupal.t('From') + '<input type="date" class="qs-date-from" /></label>' +
          '<label>' + Drupal.t('To') + '<input type="date" class="qs-date-to" /></label>' +
          '</div>';
        var apply = function () {
          var from = node.querySelector('.qs-date-from').value;
          var to = node.querySelector('.qs-date-to').value;
          var min = from ? Math.floor(new Date(from + 'T00:00:00').getTime() / 1000) : undefined;
          var max = to ? Math.floor(new Date(to + 'T23:59:59').getTime() / 1000) : undefined;
          if (typeof onChange === 'function') { onChange(min, max); }
          renderOptions.refine([min, max]);
        };
        node.querySelector('.qs-date-from').addEventListener('change', apply);
        node.querySelector('.qs-date-to').addEventListener('change', apply);
      }
    };
    return instantsearch.connectors.connectRange(render)({ attribute: attribute });
  };

}(Drupal));
