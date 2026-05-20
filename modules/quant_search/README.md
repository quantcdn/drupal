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
