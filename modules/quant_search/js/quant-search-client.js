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
 * quant_search_build_filters). The client-side differences are sort, where
 * Algolia applies the project's custom ranking on the index and Typesense
 * needs it as sort_by on every query (Drupal.quantSearch.sortBy), and the
 * date-filter schedule clause (Drupal.quantSearch.scheduleClause).
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

  /**
   * The local calendar day of a Unix timestamp, as a YYYYMMDD integer (the
   * format the indexer writes to {field}_days).
   */
  Drupal.quantSearch.timestampToDay = function (ts) {
    var d = new Date(ts * 1000);
    return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate();
  };

  /**
   * The schedule clause for a bounded date filter, in the backend's syntax.
   *
   * The indexer writes {field}_schedule (1 daily, 2 on listed days, 3 unknown
   * days) and {field}_days (YYYYMMDD integers). Inside the from..to range, an
   * event matches when it is daily, or on a listed day in the range, or, for
   * a range longer than one day, of unknown schedule. An open-ended range
   * ("coming up") needs no clause. Returns '' when no clause applies.
   */
  Drupal.quantSearch.scheduleClause = function (cfg, attribute, from, to) {
    if (from === undefined || to === undefined) { return ''; }
    var a = Drupal.quantSearch.timestampToDay(from);
    var b = Drupal.quantSearch.timestampToDay(to);
    var schedule = attribute + '_schedule';
    var days = attribute + '_days';
    var typesense = cfg.backend === 'typesense';
    var terms = typesense ?
      [schedule + ':=1', days + ':[' + a + '..' + b + ']'] :
      [schedule + ' = 1', days + ':' + a + ' TO ' + b];
    if (b > a) { terms.push(typesense ? schedule + ':=3' : schedule + ' = 3'); }
    return '(' + terms.join(typesense ? ' || ' : ' OR ') + ')';
  };

  /**
   * Records the schedule clause of one date facet and returns the full
   * filter string: the page's own filters AND every active clause.
   */
  Drupal.quantSearch.dateFilters = function (cfg, attribute, from, to) {
    cfg._scheduleClauses = cfg._scheduleClauses || {};
    cfg._scheduleClauses[attribute] = Drupal.quantSearch.scheduleClause(cfg, attribute, from, to);
    var parts = [cfg.filters || ''];
    Object.keys(cfg._scheduleClauses).forEach(function (key) {
      parts.push(cfg._scheduleClauses[key]);
    });
    return parts.filter(Boolean).join(cfg.backend === 'typesense' ? ' && ' : ' AND ');
  };

  function hasNumericRefinement(state, attribute) {
    var ops = (state.numericRefinements || {})[attribute] || {};
    return Object.keys(ops).some(function (op) { return ops[op] && ops[op].length; });
  }

  /**
   * Drops a date facet's schedule clause when its numeric refinements are
   * gone (a "clear refinements" click), so no stale clause stays active.
   * Call from the widget's render().
   */
  Drupal.quantSearch.syncDateFilters = function (helper, cfg, attribute) {
    var clauses = cfg._scheduleClauses || {};
    if (!clauses[attribute] || hasNumericRefinement(helper.state, attribute + '_start') ||
        hasNumericRefinement(helper.state, attribute + '_end')) {
      return;
    }
    helper.setQueryParameter('filters', Drupal.quantSearch.dateFilters(cfg, attribute));
    helper.search();
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
