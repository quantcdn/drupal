# Quant Search (Drupal 7)

Indexes Drupal content into Quant's hosted search and provides faceted search
pages — served at their own URL and/or embedded as blocks — plus an
autocomplete block.

## Requirements

- The `quant` and `quant_api` modules, configured with valid Quant API
  credentials (`admin/config/services/quant/api`).
- The `token` module.
- The Quant project must have **hosted search enabled**.

## Setup

1. Enable the module: `drush en quant_search`.
2. Configure indexing at `admin/config/services/quant/search/entities` — choose
   content types, the body view mode, token patterns, and any date fields.
3. Re-index existing content at `admin/config/services/quant/search/index`.
   New and updated content is indexed automatically on save.
4. Create search pages at `admin/config/services/quant/search/pages` — set a
   route and/or expose the page as a block, and add facets (taxonomy, content
   type, language, date range, or custom).

## Static export / seeding

`quant_search` implements `hook_quant_seed_queue()`, so when the `quant`
module seeds the site to QuantCDN every **enabled search page with a route**
is automatically queued — no need to list it in the seed "custom routes" box.
The seeded snapshot is the HTML shell plus the InstantSearch JS and the
injected read-only search credentials; because the search runs entirely
client-side, the static page is fully functional (facets and all) against the
live search backend.

Re-seed a search page after changing its facet or display configuration —
those settings are baked into the snapshot. New *content* does not require
re-seeding the page; it is queried live.

App deployments (server-rendered Drupal, e.g. on Quant Cloud) serve the route
live and do not need seeding.

## Indexing lifecycle

Content stays in sync with the index automatically:

- **Publish / update** a published node → it is (re)indexed.
- **Unpublish or delete** a node → it is removed from the index (all of its
  content chunks) via `DELETE /v1/search`.

Both run in a post-response shutdown function, so content operations take no
latency hit.

## Known limitations

- **URL alias changes.** If a node's path alias changes, the record at the old
  URL is orphaned until the next full re-index (the new URL is indexed
  immediately). Re-index after bulk alias changes.
- **Bundle config changes.** Removing a bundle from the indexing config does
  not retroactively purge already-indexed records of that bundle; run a full
  re-index to reconcile.

## Customisation

The module exposes three extension points so sites can customise behaviour
without modifying module code.

### 1. Hit card markup — `Drupal.quantSearch.renderHit`

Override the default hit renderer in your own JS (loaded after this module's
JS — use the "Additional JS to attach" textarea on the search page or attach
from your own module). Every indexed field on the hit is available; the active
date-facet state is on `cfg._dateFacetKeys` and `cfg._dateRefinements`.

Each configured date field is indexed as three attributes: `{field}` (the
list of session start/end timestamps), `{field}_start` (earliest start) and
`{field}_end` (latest end). The date-range widget filters with an overlap
test on the scalar pair (`{field}_start <= to AND {field}_end >= from`), so
an event already underway matches a range inside it. Sort the index by
`{field}_start` (ascending) for a "soonest first" listing that keeps
ongoing exhibitions at the top.

```js
Drupal.quantSearch.renderHit = function (hit, cfg) {
  var url = Drupal.quantSearch.safeUrl(hit.url);
  var image = Drupal.quantSearch.safeUrl(hit.image);
  var img = image ? '<img src="' + image + '" alt="" class="qs-hit-image" onerror="this.hidden=true" />' : '';
  var soldOut = (hit.field_booking_status_label === 'Booked out')
    ? '<span class="qs-badge qs-badge--sold-out">' + Drupal.t('Sold Out') + '</span>'
    : '';
  var cost = hit.field_cost
    ? '<div class="qs-hit-cost">' + Drupal.t('Cost: @cost', {'@cost': hit.field_cost}) + '</div>'
    : '';
  var sessions = Drupal.quantSearch.formatSessionInfo(
    hit, cfg._dateFacetKeys || [], cfg._dateRefinements || {}
  );
  return '<a class="qs-hit" href="' + url + '">' + img + soldOut +
    '<h4 class="qs-hit-title">' + Drupal.checkPlain(hit.title || '') + '</h4>' +
    sessions + cost + '</a>';
};
```

## Coexisting with the quant module's page pushes

The quant module pushes each rendered page to Quant, and the platform indexes
that page with the project's search extractors, keyed by URL, the same key
this module uses. For every node this module indexes, it marks the page push
with `search_record.skip`, so the platform leaves that URL's record to this
module. Without that, a page push after a re-index replaces the rich record
with an extractor record, and the node silently drops out of date filters and
facets. Pages this module does not index keep the platform's page record.

## Backends

A project runs on Algolia (public cloud) or Typesense (QuantGov cloud). The
module resolves the backend from the Quant API (`platform_mode`) and ships
the same settings to the browser either way: `backend`, `read_key`, `index`,
`endpoint`, `custom_ranking`. Filter strings are built server-side in the
backend's own syntax (`quant_search_build_filters()`), so the browser gets a
string it can use as-is. `js/quant-search-client.js` covers the rest:
`Drupal.quantSearch.createSearchClient(cfg)` returns an InstantSearch client
for either backend, and `Drupal.quantSearch.sortBy(cfg)` turns the project's
custom ranking into a Typesense `sort_by`. Themes that override
`Drupal.quantSearch.build` must use those two helpers instead of calling
`algoliasearch()` directly.

A search page's *manual filter string* is passed through verbatim, so it must
be written in the syntax the project's backend expects (Algolia
`field:'value'`, Typesense ``field:=`value```).

The resolved backend is cached for 60 seconds (30 seconds when the API is
unreachable or search is disabled) under a cache id keyed by the Quant API
settings, so a project change resolves afresh; `drush cc all` clears it.
The Typesense endpoint is configurable under *Configure indexing → Backend*
(default `https://search.quantgov.cloud`) and is read live, not cached. For local testing against a
private Typesense, set in settings.php:

```php
$conf['quant_search_backend_override'] = array(
  'backend' => 'typesense',
  'endpoint' => 'http://localhost:8108',
  'read_key' => 'localkey',
  'index' => 'my_collection',
  'custom_ranking' => array('asc(field_event_session_start)', 'asc(title)'),
);
```

JS unit tests: `node --test modules/quant_search/tests/js/quant-search-client.test.js`.

Other helpers available on `Drupal.quantSearch`: `safeUrl(url)`, `formatSessionInfo(hit, keys, refinements)`, `applyLayout(instance, layout)`, `dateRangeWidget(container, attribute, onChange)`, `radioWidget(container, attribute, limit)`.

### 2. Server-side settings mutation — `hook_quant_search_settings_alter`

Implement the hook from a custom module to mutate the per-instance JS
settings before they reach the browser. See `quant_search.api.php` for the
full hook signature.

```php
function mymodule_quant_search_settings_alter(array &$settings, array $page) {
  if ($page['machine_name'] === 'whats_on_qs') {
    foreach ($settings['facets'] as &$facet) {
      if ($facet['facet_key'] === 'event_type_en') {
        $facet['widget'] = 'pills';
      }
    }
  }
}
```

### 3. Per-page attached assets

The search-page admin form has two textareas — "Additional JS to attach" and
"Additional CSS to attach" — accepting one path or URL per line. Use them to
ship your `renderHit` override script and matching CSS without writing a
custom module.
