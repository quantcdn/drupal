# Drupal site to secured: status after items 1 to 5

Date: 2026-11-08. Nothing is pushed. Rulings: `docs/2026-11-08-drupal-to-secured-ledger.md`.

## Branches

| Repo | Branch | Base | Commits |
|---|---|---|---|
| quant-drupal | `docs/drupal-to-secured` | origin/2.x e7a1b95 | design, plan, ledger, this file |
| quant-drupal | `feat/oauth-connect` | docs branch | fa99dd6 credentials service; c5fdcb7 Log in to Quant; 7c2edb2 no token, no call (F5) |
| quant-drupal | `feat/webform-quant-forms` | feat/oauth-connect | 9fdb01b push keys + signed fetch; bc97901 registration; 12d23ea browser wizard; f03b773 tests; F5 copy; 243b63c token limitation; b1ba6d2 embedded forms post to their page |
| quant-drupal | `feat/search-page-setup` | feat/oauth-connect | 8801540 schema fix; 271cf44 Create a search page; F5 copy; 7051840 free default address |
| quant-drupal | `feat/views-search-converter` | feat/search-page-setup | b1e0218 converter; 505c398 engine syntax |
| quant-drupal | `integration/drupal-to-secured` (local only) | merges of the four feature branches | used for the demo run |
| agent-skills | `docs/drupal-oauth-forms-search` | publish/main | bc8fecd, 30f6d7b, wording fix |
| quant-docs | `docs/drupal-oauth-forms-search` | origin/develop | d82dbed |
| portal | `sec/drupal-quant-oauth-client` | origin/develop | a4a44a22c plan; 8348de61e client (approved); docs fix |
| portal | `feat/search-page-wizard-ux` | origin/develop | 1a915869d render fix + test; ad689d356 one-screen form; a2e51d6df sec(livewire-authz) template and position lists |

Worktrees: ~/apps/.quant-drupal-wt/{design,oauth-connect,webform-forms,search-setup},
~/apps/.portal-wt/{drupal-quant-oauth,search-page-ux}.

## Tests

- quant-drupal, whole module on the scratch Drupal 11.4.12 site (SQLite, PHP 8.5):
  oauth-connect 155 pass; webform-forms 166 pass; search-setup 160 pass. One existing warning
  (QuantApiFilesTest writes to sites/default) and one existing skip. phpcs Drupal standard clean on
  all changed files.
- New tests: OAuthAuthServiceTest (13, kernel), OAuthConnectTest (7, functional, fake dashboard
  that checks PKCE, bearer and project limit), QuantFormsMapperTest (7, kernel),
  QuantFormsExportTest (3, functional: export render, page cache, save publishes with config),
  CreateSearchPageTest (4, functional).
- Browser: the demo's service_request Webform (copied config) as a static export page, driven with
  Playwright against a fake Quant edge: step checks, a conditional required checkbox group, the
  address composite, two files (posted as attachments and attachments_1), the review, one JSON
  submit, the confirmation in place, Edit back to the last page.
- portal (wt-phpunit.sh): OAuth 199 pass; search and next UI suites 342 pass. Nine failures in
  WafReportNextTest, StudioCollectionBrowserTest, AutoPublishServiceTest, AiSearch LifecycleTest,
  ShellTest and XssEscapingTest also fail on clean origin/develop (same set, compared).

## Demo site (Tolmerra)

Run on 2026-11-08 with the integration branch against a fake content API (R39). Forms, the
wizard in a browser, the footer newsletter and Create a search page all worked. Afterwards
reset.sh left the site with no Quant module, config equal to the sync folder and a clean git tree.

## Still open

- Live OAuth test against a dashboard with the drupal-quant client (F2).
- Portal captures (R29, parked with the controller).
- Merge timing of the docs and skill branches (R43).
- Edge bug note F4 for the edge owner.

## Staging rehearsal, 2026-11-09 (Tolmerra against dash.stage.quantgov.cloud)

| Area | Result |
|---|---|
| Seed (nodes, terms, theme assets, Views pages, files, robots) | 188 queued, 472 items sent in 65 s with 5 workers; 183 content items in the dashboard; crawl of the preview domain: 172 pages, 200 assets, no broken links except the 3 form pages before opt-in |
| Save, rename, unpublish, delete | Save live in about 6 s. Rename left a 404 at the old alias (fixed: 301). Unpublish reaches the edge in about 19 s. Delete left /node/N redirecting to a gone page (fixed) |
| Quant Forms opt-in | 3 Webforms (contact, newsletter, 4-step service_request) registered; pages published with the form config; dashboard lists the 3 forms |
| Managed in Drupal (portal) | Missing on develop: feat/forms-managed-in-drupal was never merged. Merged and pushed (122062221); 24 form tests pass |
| Submission through the edge | **Open.** POST with all required fields returns 500 "Internal Server Error" from the staging edge (also with Accept: application/json). Missing fields return the 400 JSON message, so the failure is after validation (notifications or the queue write). Needs the staging edge logs |
| Search page from a View (News) | Proposal lists what it cannot convert; page created; worked only after the search host fix (staging needs search.stage.quantgov.cloud): 48 results, facets |
| Search page from the wizard | /site-search with content type, topic and region facets; header box suggestions work after a reseed. A second page added a second box (fixed) |
| Token refresh on cron | New token, expiry moved, still project-limited (403 on the org list); pushes work after it |
| Log out | Now also ends the login in the dashboard (portal f609fde95 + module): old token 200 before, 401 after |
| Revoked login | Detected on cron (invalid_grant), but the page still said "Connected" (fixed: warning, status report entry, "Log in to Quant again" button) |
| Endpoint unreachable | Settings page crashed (ConnectException) and the error text could show the endpoint URL (fixed) |
| Wrong platform | Not driven live (no QuantCDN demo account); the platform choice and its copy are covered by OAuthConnectTest |
| Search index after unpublish | **Not confirmed.** The total result count did not drop within 2 minutes of an unpublish, or of DELETE /search (which answered "Successfully removed search record."). The check used the total count only; a query on the page's own field is still to do |

### Branches and PRs after the rehearsal (pushed 2026-11-09, not merged)

| PR | Branch | Tests (Drupal 11.4, strict schema) |
|---|---|---|
| #262 | `fix/content-paths` (new: core fixes) | 141 pass |
| #263 | `feat/oauth-connect` (includes #262) | 181 pass |
| #264 | `feat/webform-quant-forms` (on #263) | 204 pass |
| #265 | `feat/search-page-setup` (on #263) | 187 pass |
| #266 | `feat/views-search-converter` (on #265) | 192 pass |

Integration branch: 215 pass, 1 skipped (existing). phpcs Drupal and DrupalPractice clean on all changed files.
Portal (develop): 122062221 merge of feat/forms-managed-in-drupal; f609fde95 POST /api/oauth/revoke.
