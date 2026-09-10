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
 * Two things differ and are handled here so callers stay backend-agnostic:
 *   - the `filters` string: Algolia syntax on Algolia, Typesense filter_by
 *     syntax on Typesense (Drupal.quantSearch.filtersFor / toTypesenseFilter);
 *   - sort: Algolia applies the project's custom ranking on the index,
 *     Typesense needs it as sort_by on every query (Drupal.quantSearch.sortBy).
 */
(function (Drupal) {
  'use strict';

  Drupal.quantSearch = Drupal.quantSearch || {};

  var OPERATORS = ['!=', '<=', '>=', '=', '<', '>'];
  var KEYWORDS = { and: 'and', or: 'or', not: 'not', to: 'to' };

  function isNumber(v) { return /^-?\d+(\.\d+)?$/.test(v); }
  function isBoolean(v) { return v === 'true' || v === 'false'; }

  function quoteValue(value, quoted) {
    if (value.indexOf('`') !== -1) { throw new Error('Filter values cannot contain backticks'); }
    if (!quoted && (isNumber(value) || isBoolean(value))) { return value; }
    return '`' + value + '`';
  }

  function readQuoted(input, i) {
    var quote = input.charAt(i);
    var end = input.indexOf(quote, i + 1);
    if (end < 0) { throw new Error('Unterminated quote in filter: ' + input); }
    return { token: { kind: 'word', value: input.slice(i + 1, end), quoted: true }, next: end + 1 };
  }

  function readOperator(input, i) {
    for (var k = 0; k < OPERATORS.length; k++) {
      if (input.substr(i, OPERATORS[k].length) === OPERATORS[k]) {
        return { token: { kind: 'op', value: OPERATORS[k] }, next: i + OPERATORS[k].length };
      }
    }
    return null;
  }

  function readWord(input, i) {
    var j = i;
    while (j < input.length && !/[\s():'"]/.test(input.charAt(j)) && !readOperator(input, j)) { j++; }
    if (j === i) { throw new Error('Unexpected character "' + input.charAt(i) + '" in filter: ' + input); }
    var word = input.slice(i, j);
    var keyword = KEYWORDS[word.toLowerCase()];
    return { token: keyword ? { kind: keyword } : { kind: 'word', value: word, quoted: false }, next: j };
  }

  function tokenise(input) {
    var tokens = [];
    var i = 0;
    var simple = { '(': 'lparen', ')': 'rparen', ':': 'colon' };
    while (i < input.length) {
      var ch = input.charAt(i);
      var step;
      if (/\s/.test(ch)) { i++; continue; }
      if (simple[ch]) { tokens.push({ kind: simple[ch] }); i++; continue; }
      if (ch === "'" || ch === '"') { step = readQuoted(input, i); }
      else { step = readOperator(input, i) || readWord(input, i); }
      tokens.push(step.token);
      i = step.next;
    }
    return tokens;
  }

  function Parser(tokens, source) {
    this.tokens = tokens;
    this.source = source;
    this.pos = 0;
  }

  Parser.prototype.peek = function () { return this.tokens[this.pos]; };
  Parser.prototype.next = function () { return this.tokens[this.pos++]; };
  Parser.prototype.fail = function (msg) { throw new Error(msg + ' in filter: ' + this.source); };

  Parser.prototype.parse = function () {
    if (!this.tokens.length) { return ''; }
    var out = this.parseOr();
    if (this.pos < this.tokens.length) { this.fail('Unexpected trailing tokens'); }
    return out;
  };

  Parser.prototype.parseOr = function () {
    var parts = [this.parseAnd()];
    while (this.peek() && this.peek().kind === 'or') { this.next(); parts.push(this.parseAnd()); }
    return parts.join(' || ');
  };

  Parser.prototype.parseAnd = function () {
    var parts = [this.parseUnary()];
    while (this.peek() && this.peek().kind === 'and') { this.next(); parts.push(this.parseUnary()); }
    return parts.join(' && ');
  };

  Parser.prototype.parseUnary = function () {
    var t = this.peek();
    if (t && t.kind === 'not') { this.next(); return this.parseComparison(true); }
    if (t && t.kind === 'lparen') {
      this.next();
      var inner = this.parseOr();
      var close = this.next();
      if (!close || close.kind !== 'rparen') { this.fail('Missing closing parenthesis'); }
      return '(' + inner + ')';
    }
    return this.parseComparison(false);
  };

  Parser.prototype.parseFacet = function (attr, negated) {
    var value = this.next();
    if (!value || value.kind !== 'word') { this.fail('Expected a value'); }
    if (this.peek() && this.peek().kind === 'to') {
      this.next();
      var upper = this.next();
      if (!upper || upper.kind !== 'word' || !isNumber(value.value) || !isNumber(upper.value)) { this.fail('Range bounds must be numeric'); }
      if (negated) { this.fail('NOT cannot be applied to a range'); }
      return attr + ':[' + value.value + '..' + upper.value + ']';
    }
    return attr + ':' + (negated ? '!=' : '=') + quoteValue(value.value, value.quoted);
  };

  Parser.prototype.parseNumeric = function (attr, op, negated) {
    var value = this.next();
    if (!value || value.kind !== 'word' || !isNumber(value.value)) { this.fail('Numeric comparison needs a number'); }
    if (negated) {
      if (op === '=') { op = '!='; }
      else if (op === '!=') { op = '='; }
      else { this.fail('NOT cannot be applied to an ordering comparison'); }
    }
    return attr + ':' + op + value.value;
  };

  Parser.prototype.parseComparison = function (negated) {
    var attr = this.next();
    if (!attr || attr.kind !== 'word') { this.fail('Expected an attribute name'); }
    var sep = this.next();
    if (sep && sep.kind === 'colon') { return this.parseFacet(attr.value, negated); }
    if (sep && sep.kind === 'op') { return this.parseNumeric(attr.value, sep.value, negated); }
    this.fail('Expected ":" or a comparison operator');
  };

  /**
   * Translates an Algolia `filters` string to Typesense `filter_by` syntax.
   * Mirrors lambda-handlers/search/common/filter-transforms.ts.
   */
  Drupal.quantSearch.toTypesenseFilter = function (filters) {
    if (!filters || !String(filters).trim()) { return ''; }
    return new Parser(tokenise(String(filters)), String(filters)).parse();
  };

  /** The page-level filter string in the syntax the configured backend expects. */
  Drupal.quantSearch.filtersFor = function (cfg) {
    var filters = cfg.filters || '';
    return cfg.backend === 'typesense' ? Drupal.quantSearch.toTypesenseFilter(filters) : filters;
  };

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
