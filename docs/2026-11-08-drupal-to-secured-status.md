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
