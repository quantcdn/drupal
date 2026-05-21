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

## Known limitations

- The Quant search API exposes no single-record delete. Unpublished or deleted
  content is removed from the index by the next full re-index, not immediately.

## Customisation

The module exposes three extension points so sites can customise behaviour
without modifying module code.

### 1. Hit card markup — `Drupal.quantSearch.renderHit`

Override the default hit renderer in your own JS (loaded after this module's
JS — use the "Additional JS to attach" textarea on the search page or attach
from your own module). Every indexed field on the hit is available; the active
date-facet state is on `cfg._dateFacetKeys` and `cfg._dateRefinements`.

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
