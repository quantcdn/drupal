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
   * Builds and starts one InstantSearch instance.
   */
  Drupal.quantSearch.build = function (cfg) {
    var id = function (suffix) { return '#qs-' + cfg.instance + '-' + suffix; };

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
          widgets.push(Drupal.quantSearch.dateRangeWidget(container, facet.facet_key));
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
          var img = hit.image ? '<img src="' + hit.image + '" alt="" class="qs-hit-image" />' : '';
          var summary = hit.summary ? '<div class="qs-hit-summary">' + hit.summary + '</div>' : '';
          return '<a class="qs-hit" href="' + (hit.url || '#') + '">' + img +
            '<h4 class="qs-hit-title">' + (hit.title || '') + '</h4>' + summary + '</a>';
        }
      }
    }));

    search.addWidgets(widgets);
    search.start();
  };

  /**
   * A date-range facet widget built on connectRange + native date inputs.
   *
   * The indexed attribute is a numeric Unix timestamp (or array of them for
   * recurring dates); the search backend matches a numeric range against any
   * value in the array.
   */
  Drupal.quantSearch.dateRangeWidget = function (container, attribute) {
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
          renderOptions.refine([min, max]);
        };
        node.querySelector('.qs-date-from').addEventListener('change', apply);
        node.querySelector('.qs-date-to').addEventListener('change', apply);
      }
    };
    return instantsearch.connectors.connectRange(render)({ attribute: attribute });
  };

}(Drupal));
