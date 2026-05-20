/**
 * @file
 * Quant Search — autocomplete block.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.quantSearchAutocomplete = {
    attach: function (context, settings) {
      var cfg = settings.quantSearchAutocomplete;
      if (!cfg || typeof algoliasearch === 'undefined' || !window['@algolia/autocomplete-js']) {
        return;
      }
      var mount = document.getElementById('quant-search-autocomplete');
      if (!mount || mount.getAttribute('data-qs-init')) {
        return;
      }
      mount.setAttribute('data-qs-init', '1');

      var autocomplete = window['@algolia/autocomplete-js'].autocomplete;
      var getAlgoliaResults = window['@algolia/autocomplete-js'].getAlgoliaResults;
      var client = algoliasearch(cfg.app_id, cfg.read_key);

      autocomplete({
        container: '#quant-search-autocomplete',
        placeholder: cfg.placeholder || Drupal.t('Search'),
        detachedMediaQuery: 'none',
        onSubmit: function (params) {
          window.location.href = cfg.search_path + '?q=' + encodeURIComponent(params.state.query);
        },
        getSources: function () {
          return [{
            sourceId: cfg.index,
            getItemUrl: function (params) { return params.item.url; },
            getItems: function (params) {
              return getAlgoliaResults({
                searchClient: client,
                queries: [{
                  indexName: cfg.index,
                  query: params.query,
                  params: { filters: cfg.filters || '' }
                }]
              });
            },
            onSelect: function (params) { window.location.assign(params.item.url); },
            templates: {
              item: function (params) {
                var summary = (cfg.show_summary && params.item.summary)
                  ? params.html('<div class="qs-ac-summary">' + params.item.summary + '</div>') : '';
                return params.html('<div><strong>' + (params.item.title || '') + '</strong>' + summary + '</div>');
              }
            }
          }];
        }
      });
    }
  };

}(Drupal));
