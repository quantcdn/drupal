/**
 * @file
 * Quant Search — search-client factory shared by the search page, the
 * autocomplete block, and any theme that overrides Drupal.quantSearch.build.
 *
 * A project runs on one of two hosted-search backends:
 *   - algolia:   the stock algoliasearch client (cfg.app_id / cfg.read_key).
 *   - typesense: typesense-instantsearch-adapter against cfg.endpoint with
 *                cfg.read_key, collection cfg.index.
 * Both expose the same InstantSearch searchClient, so widgets do not care.
 * Filter strings are built in the backend's own syntax server-side (see
 * quant_search_build_filters), so the only client-side difference is sort:
 * Algolia applies the project's custom ranking on the index, Typesense needs
 * it as sort_by on every query (Drupal.quantSearch.sortBy).
 */
(function (Drupal) {
  'use strict';

  Drupal.quantSearch = Drupal.quantSearch || {};

  /**
   * Typesense sort_by built from the project's custom ranking
   * (["asc(field)", ...]). Text relevance stays first, as on Algolia.
   * Returns undefined when the project has no ranking rules.
   */
  Drupal.quantSearch.sortBy = function (cfg) {
    var rules = cfg.custom_ranking || [];
    var clauses = [];
    for (var i = 0; i < rules.length && clauses.length < 2; i++) {
      var m = /^(asc|desc)\(([A-Za-z_][A-Za-z0-9_.]*)\)$/.exec(String(rules[i]).trim());
      if (m) { clauses.push(m[2] + ':' + m[1]); }
    }
    if (!clauses.length) { return undefined; }
    return ['_text_match:desc'].concat(clauses).join(',');
  };

  function typesenseNodes(endpoint) {
    var a = document.createElement('a');
    a.href = endpoint;
    var protocol = a.protocol.replace(':', '') || 'https';
    return [{ host: a.hostname, port: a.port ? parseInt(a.port, 10) : (protocol === 'https' ? 443 : 80), protocol: protocol }];
  }

  /**
   * InstantSearch-compatible search client for the configured backend.
   */
  Drupal.quantSearch.createSearchClient = function (cfg) {
    if (cfg.backend !== 'typesense') {
      return algoliasearch(cfg.app_id, cfg.read_key);
    }
    if (typeof TypesenseInstantSearchAdapter === 'undefined') {
      throw new Error('typesense-instantsearch-adapter is not loaded');
    }
    var additional = { query_by: cfg.query_by || 'title,content,summary' };
    var sortBy = Drupal.quantSearch.sortBy(cfg);
    if (sortBy) { additional.sort_by = sortBy; }
    var adapter = new TypesenseInstantSearchAdapter({
      server: { apiKey: cfg.read_key, nodes: typesenseNodes(cfg.endpoint) },
      additionalSearchParameters: additional
    });
    return adapter.searchClient;
  };

}(typeof Drupal !== 'undefined' ? Drupal : (typeof module !== 'undefined' ? (module.exports.Drupal = { quantSearch: {} }) : {})));
