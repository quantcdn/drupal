# Design: D7 `quant_search` Phase 3 — extension points + new facet widgets

**Date:** 2026-05-21
**Status:** Approved
**Repo / branch:** `quantcdn/drupal`, branch `feat/quant-search-d7-phase3` (off `feat/quant-search-d7`)

## Purpose

Make the D7 `quant_search` module customisable by sites without changing module
code, and add the two facet widget variants the State Library Victoria
`/whats-on` UX needs (radio for cost, pills for event type). The shipped Phase 1
+ Phase 2 module already gives every hit a full record of indexed fields and
exposes most internal helpers on `Drupal.quantSearch`; this phase finalises the
extension surface and documents it, plus adds the two widget modes.

The deliberate non-goal here is **any admin UI for designing card layouts**.
Sites theme their cards in their own JS/CSS; the module provides the data and
the override points.

## Context

The shipped module:

- Renders one InstantSearch instance per search page (route or block) from a
  per-instance `Drupal.settings.quantSearch[<instance>]` blob containing
  `app_id` / `read_key` / `index` / `filters` / `facets` / `display`.
- Hit cards are produced by an in-JS string template inside
  `instantsearch.widgets.hits({ templates: { item: ... } })` —
  hardcoded `title` / `session badge` / `image` / `summary` markup that
  uses `Drupal.checkPlain` and `Drupal.quantSearch.safeUrl`.
- Per-entity indexing already supports arbitrary fields via the
  `index_fields` config (`field_paid_free`, `field_cost`,
  `field_booking_status`, etc.) and resolves images from
  entityreference fields. Hits therefore carry every value a custom
  renderer would need.
- Facet widgets: `checkbox` (refinementList), `select` (menuSelect),
  `menu`, `date` (custom dateRangeWidget).

SLV's `/whats-on` adds: a free/paid radio facet, horizontal pill-styled event
type filters, and a card design with image / "Cost: …" line / Sold-Out badge /
ONLINE tag. The first two need new widget modes; everything about the card is
SLV's own theme work.

## Decisions

| Topic | Decision |
|-------|----------|
| Card design customisation | **JS extension point** — overridable `Drupal.quantSearch.renderHit(hit, cfg)`. No admin UI. |
| Server-side customisation | **`hook_quant_search_settings_alter(&$settings, $page)`** invoked just before settings ship to `Drupal.settings`. |
| Site-asset attachment | **Per-page `attached_js` / `attached_css` textfields** on the search-page admin form, processed through `drupal_add_js` / `drupal_add_css`. |
| New facet widgets | `radio` (single-select with radio inputs) and `pills` (multi-select, horizontal button styling). |
| Documentation | Customisation section in the module `README.md` covering all three extension points with one concrete SLV-style example. |
| Out of scope | Card-designer admin UI, Drupal-theme template overrides for hits (they're a JS-rendered island), SLV's actual whats-on card. |

## Architecture

### 1. New facet widgets — `radio` and `pills`

Two new values added to the existing widget `<select>` in the search-page facet
row, and two new `case` branches in the facet switch in `quant-search.js`.

- **`radio`** — single-select. Implemented on top of InstantSearch's
  `connectMenu` connector with a custom render that emits a
  `<form>` of grouped `<input type="radio">` items keyed by the
  facet attribute. Selecting an option refines to a single value; an
  "All" radio clears the refinement. Matches the SLV `/whats-on`
  cost filter exactly.
- **`pills`** — multi-select. Identical semantics to the existing
  `checkbox` widget (`connectRefinementList`), but rendered as a row
  of toggle buttons with a `quant-search--widget-pills` CSS hook.
  Standard `refinementList` widget with a CSS variant; no new
  connector. Matches the SLV event-type pill row.

Both modes write the same `facet_key` and `facet_container` shape as existing
facets, so server-side computation, filter building, and `addFacets()`
registration are unchanged.

CSS lives in `quant_search.css` under `.quant-search--widget-pills` and
`.quant-search--widget-radio`.

### 2. Overridable hit renderer

The hits widget's item template becomes:

```js
templates: {
  empty: '<p>' + Drupal.t('No results found.') + '</p>',
  item: function (hit) { return Drupal.quantSearch.renderHit(hit, cfg); }
}
```

`Drupal.quantSearch.renderHit(hit, cfg)` is added as a module-global function
whose default implementation produces today's markup (title, image with
`onerror` hide, session badge, summary, all escaped). Sites override it by
assigning to `Drupal.quantSearch.renderHit` after the module's JS loads:

```js
Drupal.quantSearch.renderHit = function (hit, cfg) {
  var url = Drupal.quantSearch.safeUrl(hit.url);
  var soldOut = hit.field_booking_status_label === 'Booked out';
  // …site-specific markup, reading any indexed field from hit…
};
```

The signature is documented in the README. The function is single-global by
design — sites that need per-instance variation can branch on `cfg.instance`
inside their override.

### 3. PHP-side settings alter hook

In `quant_search_render($page)`, after the `$settings` array is built and
before it is attached:

```php
drupal_alter('quant_search_settings', $settings, $page);
```

A `quant_search.api.php` file documents `hook_quant_search_settings_alter(&$settings, $page)` with one
realistic example showing how a custom module can flip a facet widget to
`pills`, append an `extra_fields` whitelist, or override `display.layout` for a
specific request.

The hook fires once per render, gets the full per-instance settings array, and
receives the loaded search-page record as the second argument so themes can
condition on machine name or route.

### 4. Per-page attached JS / CSS

Two textareas on the search-page admin form, inside the `display` fieldset:

- `attached_js` — newline-separated paths; each is run through
  `drupal_add_js($path, array('every_page' => FALSE))` during render.
- `attached_css` — same, through `drupal_add_css`.

Stored in the `config` blob under `display.attached_js` / `display.attached_css`.
Paths starting with `http://` or `https://` are treated as external by inferring
the type. Empty values are ignored.

Lets a site point at its own `whats-on-card.js` (the `renderHit` override) and
matching CSS without writing a custom module.

## Files touched

| Path | Change |
|------|--------|
| `quant_search.pages.inc` | Add `radio` and `pills` to the widget options; add `attached_js` + `attached_css` textareas to the display fieldset; persist + unpack the two new fields |
| `quant_search.theme.inc` | `drupal_alter('quant_search_settings', $settings, $page)` invocation; iterate `display.attached_js` / `display.attached_css` into the render array's `#attached` |
| `quant_search.api.php` (new) | `hook_quant_search_settings_alter` example |
| `js/quant-search.js` | Refactor hits template to call `Drupal.quantSearch.renderHit`; define the default `renderHit`; add `radio` and `pills` cases in the facet switch; ensure pills widget tags its container with the `quant-search--widget-pills` class |
| `css/quant-search.css` | `.quant-search--widget-pills` and `.quant-search--widget-radio` styles |
| `README.md` | New "Customisation" section: the three extension points + a small SLV-style example for `renderHit` |

## Backwards compatibility

- Default `renderHit` matches the existing hardcoded template byte-for-byte, so
  sites that don't override it see no change.
- Existing facet widgets (`checkbox`, `select`, `menu`, `date`) are untouched.
- Existing search-page records continue to load: the new `attached_js` /
  `attached_css` keys fall back to empty arrays via `quant_search_page_unpack`'s
  nested-defaults block, so older saved records don't error.

## Testing

End-to-end on the running SLV dev site (`/Users/stuart/apps/slv-migration/www-slv-qc/`)
against the live `wwwslvvicgovau-dev` Quant project:

1. Add a `radio` facet to the `whats_on_qs` search page on `field_paid_free`;
   confirm the radio inputs render and selecting one refines to a single value;
   confirm "All" clears the refinement.
2. Switch the existing taxonomy facet to `pills`; confirm horizontal layout and
   that multi-select still works.
3. Implement `Drupal.quantSearch.renderHit` in a one-file site-level JS
   referenced via the new `attached_js` textarea; confirm the override fires
   for every hit, has access to every indexed field, and the default
   implementation is untouched on other search pages.
4. Add a `hook_quant_search_settings_alter` in a tiny test module to confirm
   it can flip the `display.layout` and adjust a facet's widget.
5. `php -l` and `node --check` sweep.

## Out of scope

- A card-designer admin UI.
- Drupal-theme `.tpl.php` templates for hits (they are rendered client-side by
  InstantSearch; the override mechanism is the JS function).
- SLV's actual whats-on card markup, the ONLINE indicator field plumbing, and
  any SLV-theme work. Those are SLV-specific configuration on top of this
  module's extension surface.
- Authentication / API token rotation — unchanged from Phase 1.

## Open items resolved during planning

1. The exact `drupal_add_js` / `drupal_add_css` options used for the per-page
   `attached_*` items (`external` vs `file`, weight, scope). Reasonable
   defaults are inferred from path scheme; the plan pins the exact options.
2. The pills widget's `refinementList` template — whether to keep the
   checkbox checkmark or hide it via CSS. Default is hide; final
   styling refined in implementation.
3. README example wording for the SLV-style `renderHit` override.
