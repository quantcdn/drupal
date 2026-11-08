# Drupal site to secured: rulings ledger

Each ruling is reversible unless marked. Format: id, date, ruling, reason.

- R1 (2026-11-08): Base all module work on `origin/2.x` (e7a1b95). The local `2.x` is stale.
  `fix/composer-project-prefix` is not merged yet and stays out.
- R2: Keep the v1 content calls on the project write token. The v1 API does not accept the
  OAuth bearer. Same model as the WordPress plugin 2.0.x.
- R3: Limit the OAuth token to the chosen project (`allow_project_limit`). Reason: an org-wide
  `projects:read` token with `with_token=1` can read the write token of every project in the
  organisation. A leaked Drupal database must expose only one project. (Pending controller review.)
- R4: Tokens live in KeyValue `quant_api.oauth`, never in exportable config.
- R5: Multi-domain sites stay on token connect in this release.
- R6: Forms config goes inline in the content push, not through a separate `POST /v1/form`
  call, except to disable a form.
- R7: Multi-page Webforms are not registered; the module warns instead.
- R8: Item 3 builds on the Drupal-rendered `quant_search_page`; no new portal API.
  (Pending controller confirmation.)
