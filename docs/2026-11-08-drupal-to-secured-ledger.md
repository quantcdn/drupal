# Drupal site to secured: rulings ledger

Each ruling is reversible unless marked. Format: id, date, ruling, reason.

- R1 (2026-11-08): Base all module work on `origin/2.x` (e7a1b95). The local `2.x` is stale.
  `fix/composer-project-prefix` is not merged yet and stays out.
- R2: Keep the v1 content calls on the project write token. The v1 API does not accept the
  OAuth bearer. Same model as the WordPress plugin 2.0.x.
- R3: Limit the OAuth token to the chosen project (`allow_project_limit`). Reason: an org-wide
  `projects:read` token with `with_token=1` can read the write token of every project in the
  organisation. A leaked Drupal database must expose only one project. Approved by the controller
  2026-11-08. A "Change project" button restarts the login flow.
- R4: Tokens live in KeyValue `quant_api.oauth`, never in exportable config.
- R5: Multi-domain sites stay on token connect in this release. Approved. The UI says so next to
  the login button when multi-domain overrides exist.
- R6: Forms config goes inline in the content push, not through a separate `POST /v1/form`
  call, except to disable a form.
- R7 (superseded by R9): Multi-page Webforms are not registered; the module warns instead.
- R8: Item 3 builds on the Drupal-rendered `quant_search_page`; no new portal API. Approved.
  The portal wizard UX pass stays. The `search_options` / `template_options` bug is its own
  fix commit with a test.
- R9 (controller, 2026-11-08): Investigate client-side wizard pages in the static output (all
  pages in one form, JavaScript steps, client validation per page, one final submit). Support
  it if it fits the item; otherwise report why, with a size estimate, before dropping it.
- R10 (controller): AJAX submit, drafts, limits, non-email handlers, page and modal
  confirmations, Turnstile and hCaptcha stay unsupported with a clear warning. Add the
  "redirect to URL" confirmation if it is cheap.
- R11 (controller): Disconnect deletes the local tokens only in this release.
- R12 (controller): Document `POST /v1/form` in item 5.
- R13 (controller): Use the scratchpad d11 site until the demo site at ~/apps/quant-demo-drupal
  reports ready.
- R14: The `drupal-quant` redirect patterns cover the module callback at the web root on any
  host and port. A Drupal site in a subdirectory uses token connect. Same rule as `wordpress-quant`.

## Follow-ups

- F1: Portal OAuth revoke endpoint (RFC 7009), so that disconnect also revokes the tokens.
  For the controller to schedule.
