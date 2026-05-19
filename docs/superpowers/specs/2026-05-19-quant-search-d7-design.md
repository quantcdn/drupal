# Design: Drupal 7 `quant_search` module

**Date:** 2026-05-19
**Status:** Approved
**Repo / branch:** `quantcdn/drupal`, branch `feat/quant-search-d7` (off `7.x-1.x`)

## Purpose

Build a Drupal 7 `quant_search` submodule for the `quant` (QuantCDN) module — a
Drupal 7 port of the Drupal 10/11 `quant_search` module. It lets a site:

1. Index Drupal content into Quant's hosted search service.
2. Create and manage faceted "search pages" — search/filter landing pages —
   served at their own URL and/or embedded as blocks.
3. Provide a search autocomplete block.

The driving use case is the State Library Victoria corporate site: replacing
ad-hoc faceted listing pages (e.g. `/whats-on`) with Quant-search-backed search
pages that support taxonomy, content-type, language, and **date/calendar**
filters.

## Context

### Reference: the Drupal 10/11 `quant_search` module

Located at `/Users/stuart/apps/quant-drupal/modules/quant_search` (branch `2.x`).
It provides: a `QuantSearchPage` config entity; an indexing pipeline that
piggybacks "search records" onto QuantCDN content pushes; dynamically generated
routes serving an HTML shell + Algolia InstantSearch.js (100% client-side
faceted search); an autocomplete block; and a per-entity index configuration
form. Facet types: taxonomy, content type, language, custom.

### Host: the Drupal 7 `quant` module

The `7.x-1.x` branch of `quantcdn/drupal` is a single project (`quantcdn`) with a
parent `quant` module plus `modules/` submodules: `quant_api`, `quant_cron`,
`quant_file`, `quant_redirect`, `quant_scheduler`, `quant_sitemap`. There is no
`quant_search` — this design adds it.

Key reuse points:
- `quant_api` provides `quant_api_get_request_headers()` (assembles the
  `Quant-Customer` / `Quant-Project` / `Quant-Token` auth headers from the
  `quant_api_*` variables) and `QUANT_API_ENDPOINT_DEFAULT`. The new module
  reuses these — no new credentials.
- The parent module already passes a `search_record` key through to the API if
  present, and uses `drupal_register_shutdown_function()` for its reactive
  content pushes — a pattern this module mirrors.

### The Quant search backend

Quant's hosted search exposes an **Algolia-compatible API** but is **Typesense**
under the hood. Consequences for this design:
- The Algolia InstantSearch.js client library is used as the frontend client,
  but it must be pointed at **Quant's search host** with the credentials the
  Quant API returns — never Algolia's default `*.algolia.net` hosts.
- Field names in this module's config and JS are kept search-neutral
  (`search_*`), not `algolia_*`, so nothing implies a hard Algolia dependency.

## Decisions

| Topic | Decision |
|-------|----------|
| Scope | Full feature parity with the D9 module: indexing pipeline, search pages, autocomplete block, per-entity index config UI. |
| Deliverable | The generic, releasable D7 module only. Configuring SLV's actual replacement pages is a separate follow-up. |
| Implementation style | Lean and D7-idiomatic — deliver every capability, but built the natural D7 way, not a transliteration of the D9 architecture. |
| Search-page storage | A custom DB table with a small CRUD API. No `ctools` dependency. |
| Indexing trigger | Real-time via a shutdown function on entity save/delete (the parent module's pattern) + a manual batch re-index form. |
| Search-page routing | `hook_menu()` enumeration — one menu item per enabled search page; `menu_rebuild()` on save. |
| Block embedding | Each search page can additionally be exposed as a block. |
| Date filters | A first-class `date_range` facet type (new — the D9 module has no date facet). |

## Architecture

### File structure

A new submodule, `modules/quant_search/`:

```
modules/quant_search/
  quant_search.info          Module metadata; deps quant, quant:quant_api, token
  quant_search.install       hook_schema (quant_search_page table), hook_uninstall
  quant_search.module        hook_menu, hook_theme, hook_block_*, hook_permission,
                             entity hooks (node/term insert/update/delete),
                             page-render + block-render entry points
  quant_search.admin.inc     Overview/status, entities-config, batch-index,
                             clear-index forms
  quant_search.pages.inc     Search-page add/edit/delete form + CRUD helpers
  quant_search.api.inc       Quant search API client functions
  quant_search.index.inc     Search-record generation, batch re-index ops,
                             shutdown-function push
  quant_search.theme.inc     Preprocess + shared search-page render builder
  templates/
    quant-search-page.tpl.php
  js/
    quant-search.js          InstantSearch init (ported from D9)
    quant-autocomplete.js    Autocomplete (ported from D9)
  css/
    quant-search.css
```

Each file has one clear responsibility; the API client, indexing, admin forms,
page CRUD, and rendering are separable units.

### `.info`

```ini
name = Quant Search
description = Index content to Quant hosted search and build faceted search pages.
package = Quant
core = 7.x
configure = admin/config/services/quant/search
dependencies[] = quant
dependencies[] = quant:quant_api
dependencies[] = token
```

## Data model

### Table `quant_search_page`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | serial, PK | Internal id |
| `machine_name` | varchar, unique | Stable identifier (route, block delta, JS instance id) |
| `label` | varchar | Admin label |
| `status` | int | Enabled (1) / disabled (0) |
| `route` | varchar, nullable | URL path, e.g. `whats-on`. Empty = block-only |
| `expose_block` | int | Whether to expose this page as a block |
| `title` | varchar | Page heading shown to visitors |
| `description` | text | Intro text shown on the page |
| `config` | blob (serialized) | Facets, filters, display options (see below) |

The serialized `config` blob holds:
- `languages[]` — restrict results to these language codes.
- `bundles[]` — restrict results to these content types.
- `manual_filters` — a raw filter string passed through to the search query.
- `facets[]` — ordered list; each facet has: `widget` (checkbox / select / menu /
  date), `type` (taxonomy / content_type / language / date_range / custom) plus
  type config (vocabulary, date attribute + date-widget style, or custom key),
  `heading`, `language`, `limit`, `weight`.
- `display{}` — `results` (show search box, show stats, show clear-refinements)
  and `pagination` (enabled, per-page count).

Storing facets/filters/display as one serialized blob (rather than normalised
tables) is deliberate — it is a standard D7 pattern and keeps the module lean.

CRUD helpers in `quant_search.pages.inc`: `quant_search_page_load($id)`,
`quant_search_page_load_by_name($name)`, `quant_search_page_load_all()`,
`quant_search_page_save($page)`, `quant_search_page_delete($id)`.

### Per-entity index configuration

Stored in the `quant_search_entities` variable (`variable_get`/`variable_set`):
a map of entity type → `{ enabled, bundles[], languages[], view_mode,
token mappings (title / summary / image), date_fields[] }`. No table — this is
single-row site config and matches D7 conventions.

## Indexing pipeline (`quant_search.index.inc`)

### Real-time

`hook_node_insert`/`hook_node_update` (and taxonomy term equivalents when terms
are a configured indexed type) register a **shutdown function** that builds the
search record and sends it to the Quant API — after the response is returned to
the user, so content saves take no latency hit. `hook_node_delete` removes the
record from the index.

### Search-record generation

`quant_search_generate_record($entity, $entity_type, $langcode)` builds a record
matching the shape the Quant search backend expects (kept compatible with the D9
module's record so a site can switch Drupal versions without re-indexing):
- `title`, `summary`, `image` — via `token` replacement against the configured
  token patterns.
- `content` — the entity rendered in the configured view mode, `strip_tags`'d.
- `url` — the canonical alias.
- `content_type`, `lang_code`, language label.
- Taxonomy tags — gathered per vocabulary, keyed `{vocabulary}_{langcode}`.
- **Date attributes** — date and datetime field values extracted to numeric
  Unix timestamps. Multi-value / recurring date fields (e.g. an event's
  `field_event_session`) index as an **array of timestamps**, so "any
  occurrence within range" filtering works.

### Batch re-index and clear

- A batch re-index form (`admin/config/services/quant/search/index`) runs an
  `EntityFieldQuery` over published content filtered by the configured bundles
  and languages, chunked at 50 entities per batch operation, each operation
  calling the API client.
- A clear-index confirmation form wipes all records via the API.

## Quant search API client (`quant_search.api.inc`)

Procedural functions over `drupal_http_request()`, using
`quant_api_get_request_headers()` for auth and
`variable_get('quant_api_endpoint', QUANT_API_ENDPOINT_DEFAULT)` for the base
URL:

- `quant_search_api_send_records($records)` — push a batch of search records.
- `quant_search_api_delete_record($url)` — remove one record.
- `quant_search_api_clear()` — wipe the index.
- `quant_search_api_add_facets($keys)` — register facetable attributes.
- `quant_search_api_project()` — fetch the project, including the search host +
  credentials (search app/host id, read-only key, index name) and whether
  hosted search is enabled.

> **Planning input:** the exact endpoint paths, payload shapes, and how the
> search client is pointed at the Quant host will be confirmed by reading the
> D9 `quant_api` `QuantClient` class and the D9 `quant-search.js` before
> implementation. The functions above are the contract; their wire details are
> resolved in the plan.

## Search page management (admin)

- **Pages list** (`admin/config/services/quant/search/pages`) — a table of
  search pages (label, machine name, route link, enabled, block-exposed) with
  add / edit / delete operations.
- **Add/edit form** (`quant_search.pages.inc`) — fields for label, machine name,
  route, "expose as block", title, description, languages, bundles, manual
  filter string; a **tabledrag-ordered facet list** with AJAX add/remove (per
  facet: widget, type + type config, heading, language, limit); and display
  options.
- **On save** — write the record, call `quant_search_api_add_facets()` with the
  page's facet keys, and `menu_rebuild()` so the route registers/updates.

## Search page rendering

A search page renders identically whether served at its route or embedded as a
block; both paths call one shared render builder in `quant_search.theme.inc`.

- **Route** — `hook_menu()` enumerates enabled `quant_search_page` records with a
  non-empty `route` and registers a menu item per route, callback
  `quant_search_render_page()`, access `access content`.
- **Block** — `hook_block_info()` returns one block per page with
  `expose_block` set (plus the autocomplete block); `hook_block_view()` calls the
  same render builder.
- **The render builder** — loads the page, fetches the search host + credentials
  via `quant_search_api_project()`, builds the filter string (languages,
  bundles, manual filters) and the translated facet keys, returns a themed
  render array, and injects `Drupal.settings.quantSearch[<instanceId>]` plus the
  InstantSearch.js library.
- **Instance-scoped IDs** — container element IDs are prefixed with the page's
  machine name, and JS config is keyed by that instance id, so a page works as
  both a route and a block and multiple search blocks can coexist on one page.
- **`quant-search-page.tpl.php`** — the HTML shell: search box, stats, hits,
  pagination, and one container per facet. Ported from the D9 Twig template.
- **`quant-search.js`** — InstantSearch.js initialisation, ported near-verbatim
  from D9 (it is framework-agnostic; it reads `Drupal.settings` instead of
  `drupalSettings`, and iterates instances). The search client is configured
  with the Quant search host from `quant_search_api_project()`. All faceted
  querying is client-side; this works whether the page is served live by Drupal
  or as a static export.

### Facet types

`taxonomy`, `content_type`, `language`, `custom` (ported from D9), plus a new
`date_range` type:
- **Indexing** — see "date attributes" above (numeric timestamps, arrays for
  recurring dates).
- **Config** — a `date_range` facet picks the indexed date attribute and a
  widget style (calendar picker / two date inputs / presets such as
  "this week" / "this month").
- **Frontend** — a date-range widget built on InstantSearch's `connectRange`
  connector wired to a lightweight calendar date picker, translating picked
  dates into a numeric range refinement on the timestamp attribute, with a plain
  two-input fallback.

> **Planning input:** the date-picker library choice and the precise
> recurring-event semantics ("event matches if any session falls in range") are
> finalised in the plan, referencing the live `slv.vic.gov.au/whats-on` page.

## Autocomplete block

`hook_block_info` / `hook_block_configure` / `hook_block_view` provide an
autocomplete block. Configuration: linked search page, placeholder text, show
summary. The block renders the input and attaches `quant-autocomplete.js` plus
`Drupal.settings.quantSearchAutocomplete` (search host/credentials and the
linked page's filters). JS ported from D9.

## Admin & permissions

- Overview/status page at `admin/config/services/quant/search` — index stats
  from the API, gated on the project having hosted search enabled.
- Local-task tabs: Overview, Pages, Index, Entities. Action link: Clear index.
- All under the parent module's existing `admin/config/services/quant` menu
  tree.
- `hook_permission()` defines `administer quant search` (gates all admin
  pages). Public search pages and blocks use the core `access content`
  permission.

## Testing

Verification runs against the live Quant project **`wwwslvvicgovau-dev`**
(organisation **`state-library-victoria`**, endpoint `https://api.quantcdn.io`)
using the running SLV Drupal 7 dev site (`www-slv-qc`).

- The Quant API token is configured at runtime through the `quant_api` settings
  form (`variable_set`). It is a secret — it is **never** committed to this
  repository, the implementation plan, or any file.
- Per-file checks: `php -l` and Drupal 7 coding standards on every new file.
- End-to-end: enable the module, configure `quant_api`, run a batch re-index,
  create a search page (route + block, with taxonomy and `date_range` facets),
  load it, and confirm InstantSearch renders and facets — including the date
  filter — query the Quant backend correctly.

## Out of scope

- Configuring SLV's actual replacement pages (`/whats-on` and others) — a
  separate follow-up that uses this module.
- Migrating data or config from the D9 `quant_search` module.
- Replicating SLV's external Primo catalogue and Imagio image searches.
- A `ctools`-exportable / Features integration for search pages (may be layered
  on later).

## Open items resolved during planning

1. Exact Quant search API endpoint paths and payload shapes — from the D9
   `QuantClient`.
2. How the InstantSearch client is pointed at the Quant (Typesense-backed)
   search host — from the D9 `quant-search.js`.
3. Date-picker library and recurring-event date-range semantics — referencing
   the live `slv.vic.gov.au/whats-on`.
