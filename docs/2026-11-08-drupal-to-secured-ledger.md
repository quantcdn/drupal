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
- R15: Item 1 is tested against a fake dashboard (test module `quant_api_oauth_test`) that checks
  PKCE, the bearer and the project limit like the portal. A live test against a local dashboard
  waits until the `drupal-quant` client is integrated: the local portal runs from the shared main
  checkout, which other sessions use.
- R16 (controller, 2026-11-08): The demo site ~/apps/quant-demo-drupal is the test target from now
  on (do not edit its content or theme; reset with reset.sh). R13 is closed.
- R17: Tokens of a pending login (between the callback and the project choice) are kept for 15
  minutes in an expirable store keyed by user, and never become the connection until the project
  limit succeeds.
- R18: The existing metadata views of `quant` fail strict config schema checks; new functional tests
  set `$strictConfigSchema = FALSE` with a comment. Not fixed in this series.
- R19 (R9 study result): Client-side wizards are feasible inside item 2, so the module supports them.
  Webform hides other pages with `#access = FALSE` (WebformWizardPage::hidePage). On a Quant export
  request only (validated `quant-token` header), the module swaps that element class for one that
  renders every page, and a script runs the steps: page validation in the browser, an optional
  review step built in the browser, one final submit to Quant Forms. Without JavaScript every page
  shows and the form posts as one page. Size: about 250 lines PHP, 300 lines JS, plus tests.
- R20: Export requests skip the page cache (a request policy on the `quant-token` header), and the
  altered form varies by that header, so an export render never reaches live visitors and a live
  render never reaches Quant.
- R21: With JavaScript the form submits with `Accept: application/json`. The confirmation types
  message, inline and page show the confirmation in place of the form; url and url_message redirect
  in the browser (the cheap "redirect to URL" case, so it is supported). Modal shows in place.
- R22: Webform tokens cannot run on a static site. The module removes them from Quant messages and
  maps `[webform_submission:values:KEY]` to Quant `{{KEY}}` only in the email subject. A per-form
  "Quant Forms" setting on the Webform overrides the message.
- R23: The edge parses a multipart body field by field: a repeated name keeps only the last value,
  and `name[]` grouping applies to urlencoded bodies only. The script therefore renames repeated keys
  (`attachments`, `attachments_1`, ...) and posts urlencoded when the form has no file. Without
  JavaScript only the last file of a multi-file field is kept (documented limit). Edge finding for
  the controller: F4.
- R24: One Quant Forms config exists per URL. A page with two registrable Webforms registers the
  first and logs a warning.
- R25: The export fetch (Seed::markupFromRoute) now always sends the signed `quant-token`; before,
  it sent it only with content drafts on. A render knows it is an export only from that header.
  With drafts off the token is not validated, so a visitor who sends the header gets the static form
  render and skips the page cache. Both are harmless (the same as a query string). Export fetches are
  POST requests, so the page cache never stored them; the request policy of R20 stays as a guard.
- R26: I did not run the demo site's `enable-quant-local.sh` yet. It runs `ddev restart` and changes
  the demo's composer files while another agent polishes that site, and the films install the module
  on camera. Item 2 is tested on the scratch site with a copy of the demo's real `service_request`
  Webform config (read only): the export render, a browser run of all steps against a fake edge, and
  the mapped config. The demo run waits for a quiet window from the controller.
- R27: A composite (address, name, contact) posts `key[part]`, never `key`. Quant checks only parts
  that are marked required (`#PART__required`, or `#required` in a custom composite's `#element`).
  Before this fix a required address made every submission fail the mandatory check.
- R28: Portal search page form: the next view is one screen, so it drops the step methods
  (`nextStep`, `previousStep`). The parity guard has a reasoned exception that cites R8. The classic
  view keeps the steps, and every field is still on the screen, so nothing is lost.
- R29: The portal views were not checked in a browser. Signed-in pages need Stuart's capture
  helper (agents never forge sessions). Theme and reflow captures are owed for the search page form
  and list.
- R30: "Create a search page" needs no new portal API. It saves the Drupal search page entity,
  extends the index only when the page needs more content types, and publishes only with a token.
- R31 (controller, 2026-11-08): F5 is approved. The API client refuses every call when it has no
  token: fail closed, one clear log line, no network call, with a test.
- R32 (controller): F4 keeps the script workaround; the bug note below goes to the edge owner.
- R33 (controller): The demo site window is open (the polish agent finished). Run
  enable-quant-local.sh and test against Tolmerra. Afterwards ./scripts/reset.sh must return the site
  to "module not installed". Do not change the site's content or theme.
- R34 (controller): R29 (portal captures) is parked; the controller schedules them with Stuart.
- R35 (controller): Set a Quant Forms message override on the demo's service_request form for the
  test, and list submission tokens as a known limitation in the quant_webform README.
- R36 (controller): Run the livewire-authz-check review over the portal search page changes before
  calling that branch done.
- R37 (supersedes R24 for forms with a page): On an export render a Webform with its own page posts
  to that page from every page that embeds it, and Quant Forms is registered only on the page a form
  posts to. Found on Tolmerra: the footer newsletter is on every page, so the contact and service
  request pages each showed two forms.
- R38: "Create a search page" proposes the first free address of search, site-search, search-2...
  Tolmerra has a Views page at /search, so it proposed /search and then refused it.
- R39 (R33 result, 2026-11-08): Tested on Tolmerra with the integration branch (local only) against a
  fake content API on the host (fake token; no real Quant service). Results: the three forms
  registered on /contact-us, /newsletter and /services/service-request with the right mandatory
  fields and email; the service request ran in a browser through all steps (conditional business
  name, address, two files, review, one submit, confirmation in the theme); the footer newsletter
  posted to /newsletter; Create a search page saved a valid page at /site-search, registered
  region_en and topic_en, published it and placed the search box. Clean-up: modules uninstalled,
  package, repository entry and ddev override removed, composer.lock restored, reset.sh run. After
  that no Quant module is installed or present, config matches the sync folder and git is clean.
- R40 (R36 result): livewire-authz-check over SearchPageWizard and SearchDashboard. Every server
  value is locked (projectName, projectId, error, canEdit, searchPageId, facetOptions, liveUrl,
  savedUrl, hasCustomCss, pendingDelete; searchPages on the dashboard). Inputs bound by wire:model
  stay unlocked (tested). save, requestDelete, cancelDelete and confirmDelete call
  authorize('edit_projects') first; confirmDelete re-derives the page in the active project and
  needs the locked request. Found and fixed: the client-set template named a Blade view and the
  facet position became a CSS class; both now come from fixed lists (sec(livewire-authz) commit).
- R41: Item 4 (Views converter) maps only what the search index holds: fixed content type filters,
  exposed content type, language and taxonomy filters, fixed term filters, keyword filters and the
  pager. Everything else is a note in the proposal. Checked against the demo's four View pages
  (news, events, publications, site_search) loaded as raw config on the scratch site.
- R42: Manual search filters go to the engine as written (Algolia on QuantCDN, Typesense on
  QuantGov). The converter writes its term filter in the project's syntax; the docs say so. I had
  first written the opposite in the docs; corrected before commit.
- R43: Item 5 docs and skill describe module 2.3.0 behaviour, which is not released. The skill
  branch (agent-skills `docs/drupal-oauth-forms-search`) and the docs branch (quant-docs
  `docs/drupal-oauth-forms-search`) must merge only after the 2.3.0 release, and the version number
  must be checked then. The docs describe the edge multipart limit (F4) as current behaviour; update
  them when F4 is fixed.
- R44: The quant-docs build stopped in the bundling step because the worktree borrowed the main
  checkout's node_modules through a symlink; content sync passed. A full build needs its own
  install in CI.
- R45 (controller, review round): all 14 review findings and Stuart's per-form settings fixed;
  mapping in docs/2026-11-08-review-fixes.md. R24 and R37 now apply only to opted-in forms.
- R46: The "Managed in Drupal" marker goes in notification_settings (a free-form table in the
  content API schema), because form_config refuses unknown keys. No API change, so no review gate.
- R47: require_project_limit_for_write_token is a dedicated client flag on drupal-quant only
  (finding 4). Approved by the controller (portal 8e47e4e96). Accepted residual risk: a phished
  approval still gets one project per approval (the consent screen names the attacker's host), the
  same as the wordpress-quant client.
- R48 (controller, 2026-11-08): portal ac720e31c approved. OAuthWriteTokenGate is a no-op for web
  requests (no api_token), covers every API response that carries a write token (v2 with_token,
  v1 project, v1 create and edit), and refuses API token creation for clients with
  require_project_limit_for_write_token, which closes the minting hole found in the re-review.

## Follow-ups

- F1: Portal OAuth revoke endpoint (RFC 7009), so that disconnect also revokes the tokens.
  For the controller to schedule.
- F2: Live end-to-end test of Log in to Quant against a dashboard with the `drupal-quant` client.
- F3: `quant` does not declare its `file` dependency (its views need it); tests enable it by hand.
- F4: Quant edge forms: multipart bodies drop repeated field names. Bug note for the edge owner:
  - File: quant repo, `docker/fastly/src/forms.js` (develop), the multipart branch of the
    submission parser (`for (const part of parts)`, about lines 300-320). The urlencoded branch uses
    `groupParamsByKey()` (lines 6-24), which the multipart branch does not call.
  - Repro: POST to a page with Quant Forms on, `Content-Type: multipart/form-data`, with two parts
    named `files[attachments][]` (two files) and two parts named `colours[]` (values red, blue).
  - Expected: both files stored and referenced (for example `attachments`, `attachments_1`), and
    `colours`, `colours_1` as in an urlencoded body.
  - Actual: `submissionData['files'][fieldName] = path` and `submissionData[fieldName] = part.data`
    overwrite, so only the last file and the last value are kept, under the raw name with brackets.
  - Also: `submissionData['files']` starts as an array but is used with string keys.
  - Impact: a Drupal Webform with a multi-file field loses files when JavaScript is off. With
    JavaScript the Quant module renames the parts first, so nothing is lost.
  - I did not copy any value from that file; it holds hard-coded credentials (reported separately).
- F5: The module calls the Quant API when no token is set: deleting a search page on an unconnected
  site sent `PATCH /v1/unpublish` to api.quantcdn.io with empty credentials (403, nothing changed).
  It happened once on the scratch site on 2026-11-08; the scratch site now points at a closed local
  port. Proposed fix: QuantClient refuses every call when it has no token.
- F6 (on the follow-up list): Apply require_project_limit_for_write_token to wordpress-quant and scriptattest after checking
  their code (WordPress reads with_token before any limit today).
- F7 (routed to Stuart by the controller): The dashboard form editor keeps the reCAPTCHA secret and the Slack webhook in public Livewire
  properties for pages not managed in Drupal (existing; against the portal secrets rule).
- F8: Portal TokenServiceScopedTest::it_creates_token_with_preset fails on origin/develop with
  "Unknown preset: deploy" (existing, not from this series).

- 2026-11-08 (controller): Portal branches sec/drupal-quant-oauth-client and feat/search-page-wizard-ux merged to
  portal develop at ed6bc7cc7. quant_webform 2.4 plan from the alert-channels session (spec
  quant-govt-architecture docs/superpowers/specs/2026-11-08-alert-channels-design.md, 7c27a645): org-wide channels
  (email | slack); list via GET /api/v3/organizations/{org}/alert-channels with a new read scope (name to come);
  form config "notifications": {"channels": ["ch_a", {"id": "ch_b", "cc": true}], "email": {subject, reply_to_field,
  template}}; no org default; the platform accepts both shapes; 2.3.0 keeps the legacy inline path; the migration
  skips "Managed in Drupal" pages.

## 2026-11-09 rehearsal rulings

- Core fixes (renamed alias redirect, delete of internal paths, view schema) go on a fifth branch, `fix/content-paths`, merged first. The stack merges it, so every test runs with strict config schema checks again.
- Log out revokes the login at the dashboard, except on a copied site (that would log out the original). Replaced and discarded logins are revoked too.
- A failed push after a save is logged, not shown: the push runs after the response. Kept for 2.3.0; a later release can show the last failure on the page info block.
- Review findings left open (pre-existing, low): the platform radio can differ from the saved platform with a non-QuantGov dashboard override; export tokens are not bound to one route.
- Quant Forms submissions through the staging edge return 500 after validation. Release waits until a submission passes on staging.

## 2026-11-09 open items closed for 2.3.0

- Failed pushes: kept per site in State (short reason only), shown to editors on the next page view (cache tag invalidated on change) and on the status report, cleared when the page is pushed again.
- Search index: an unpublished page stayed in QuantGov search. Cause in the content API (remove_record sent the URL as the Typesense ID). Fix: quantcdn/quant-section#407 (Stuart deploys). The module needs no change.
- Platform radio keeps the saved platform for a logged-in site. Export token bound to its path, with the base path of a subdirectory site.
- The edge 500 on Quant Forms was the au-stg serve task definition (quantcdn/quant-govt-architecture#427, Stuart). The submission test waits for its deploy.
