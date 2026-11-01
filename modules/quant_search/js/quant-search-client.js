/**
 * @file
 * Quant Search: the search client shared by the search page and autocomplete.
 *
 * A project runs on one of two hosted search backends, resolved server-side
 * by \Drupal\quant_search\SearchBackend:
 *   - algolia:   the Algolia client with app_id and read_key.
 *   - typesense: the Typesense InstantSearch adapter against endpoint with
 *                read_key and collection index (QuantGov).
 * Both expose the same InstantSearch search client, so widgets do not care.
 * Filter strings already use the backend's syntax. Typesense needs the
 * project's custom ranking as sort_by on every query.
 */
(function (Drupal) {
  'use strict';

  Drupal.quantSearch = Drupal.quantSearch || {};

  /**
   * The Typesense node for an endpoint URL.
   */
  Drupal.quantSearch.typesenseNodes = function (endpoint) {
    var url = new URL(endpoint);
    var protocol = url.protocol.replace(':', '') || 'https';
    var port = url.port ? parseInt(url.port, 10) : (protocol === 'https' ? 443 : 80);
    return [{host: url.hostname, port: port, protocol: protocol}];
  };

  /**
   * An InstantSearch-compatible search client for the configured backend.
   *
   * @param {object} cfg
   *   drupalSettings.quantSearch or drupalSettings.quantSearchAutocomplete.
   *
   * @return {object}
   *   The search client.
   */
  Drupal.quantSearch.createSearchClient = function (cfg) {
    if (cfg.backend !== 'typesense') {
      return algoliasearch(cfg.app_id || cfg.algolia_application_id, cfg.read_key || cfg.algolia_read_key);
    }
    if (typeof TypesenseInstantSearchAdapter === 'undefined') {
      throw new Error('typesense-instantsearch-adapter is not loaded');
    }
    var additional = {query_by: cfg.query_by || 'title,content,summary'};
    if (cfg.sort_by) {
      additional.sort_by = cfg.sort_by;
    }
    var adapter = new TypesenseInstantSearchAdapter({
      server: {apiKey: cfg.read_key, nodes: Drupal.quantSearch.typesenseNodes(cfg.endpoint)},
      additionalSearchParameters: additional
    });
    return adapter.searchClient;
  };

}(Drupal));
