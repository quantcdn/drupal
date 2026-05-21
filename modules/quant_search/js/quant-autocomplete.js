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
            getItemUrl: function (params) { return safeUrl(params.item.url); },
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
            onSelect: function (params) { window.location.assign(safeUrl(params.item.url)); },
            templates: {
              item: function (params) {
                var title = params.item.title || '';
                if (cfg.show_summary && params.item.summary) {
                  return params.html`<div><strong>${title}</strong><div class="qs-ac-summary">${params.item.summary}</div></div>`;
                }
                return params.html`<div><strong>${title}</strong></div>`;
              }
            }
          }];
        }
      });
    }
  };


  /**
   * Returns a URL that is safe to follow, or '#' if it is unsafe.
   * Accepts site-relative paths and http/https absolute URLs only.
   */
  function safeUrl(url) {
    if (typeof url !== 'string' || url === '') {
      return '#';
    }
    if (url.charAt(0) === '/' || /^https?:\/\//i.test(url)) {
      return url;
    }
    return '#';
  }

}(Drupal));
