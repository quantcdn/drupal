# Drupal site to secured: implementation plan

Design: `docs/2026-11-08-drupal-to-secured-design.md`. Rulings: `docs/2026-11-08-drupal-to-secured-ledger.md`.
Test site: Drupal 11.4 on SQLite in the session scratchpad (until the shared demo site exists).

## Branches

| Item | Repo | Branch | Base |
|---|---|---|---|
| Design, plan, ledger | quant-drupal | `docs/drupal-to-secured` | origin/2.x |
| 1 OAuth (module) | quant-drupal | `feat/oauth-connect` | origin/2.x |
| 1 OAuth (portal client) | portal | `sec/drupal-quant-oauth-client` | origin/develop |
| 2 Forms | quant-drupal | `feat/webform-quant-forms` | feat/oauth-connect (credentials service) |
| 3 Search (module) | quant-drupal | `feat/search-page-setup` | feat/oauth-connect |
| 3 Search (portal UX) | portal | `feat/search-page-wizard-ux` | origin/develop |
| 4 Views converter | quant-drupal | `feat/views-search-converter` | feat/search-page-setup |
| 5 Docs | quant-agent-skills, quant-docs | `docs/drupal-oauth-forms-search` | their default branches |

Worktrees: `~/apps/.quant-drupal-wt/<name>`, `~/apps/.portal-wt/<name>`.

## Item 1: OAuth connect

1. Portal: add the `drupal-quant` client; tests (client flip, consent Authz, project limit).
   **Stop. Send the diff summary to the controller for review.**
2. Module: config schema for `quant_api.settings`; `auth_method`, `platform` keys; update hook.
3. Module: `quant_api.credentials` service; move every direct `api_token` reader to it
   (QuantClient, quant_search Search.php / forms / module, SeedForm). Kernel test for the order.
4. Module: `OAuthTokenStore` (KeyValue), `SiteFingerprint`, `PortalUrl` (per platform).
5. Module: `AuthService` (authorize URL, PKCE, code exchange, refresh with lock and rotation
   recovery, refusal state). Unit tests ported from `ai_provider_quant_cloud`.
6. Module: `OAuthController` (connect, callback, disconnect with CSRF) and the project
   picker form (list, limit, fetch write token). Kernel and functional tests with a mocked
   HTTP client.
7. Module: settings form with two paths ("Log in to Quant", "Connect with a project token"),
   the multi-domain notice, status display; `hook_cron` refresh; `drush quant:auth-status`.
8. Manual end to end against the local portal (stage Cloud API) with a local test org only.

## Item 2: Quant Forms

1. `WebformQuantConfigMapper` + support classifier; unit tests for every row of the matrix.
2. Inline `form_enabled` / `form_config` in the content push (QuantApi `onOutput` event or a
   new event); kernel test of the payload.
3. Webform save / disable / delete hooks; queue re-push of the pages that show the form.
4. "Register all" form action and `drush quant:webform-register`.
5. Settings: Slack webhook (KeyValue), spam threshold; result container injection.
6. Admin warnings on unsupported Webforms; functional test.

## Item 3: Search page

1. Fix `quant_search_page` schema, dead service, `core/jquery.once`, docs links; kernel test
   for config schema validity.
2. Guided "Create a search page" form with facet proposal; functional test.
3. Portal: wizard UX pass (one screen, defaults, facet picker, template_options bug, delete,
   live link, edit permission); Livewire Authz test per CLAUDE.md; next-gen views via the
   portal skills.

## Item 4 (stretch): Views converter

1. `ViewToSearchPage` analyser (unit tests with fixture views), "Convert to search page" link.

## Item 5: Docs and skills

1. quant-cms-integrations skill + references/drupal.md; leak-check and lint-skills.
2. quant-docs: Drupal search page, a Drupal forms page, `POST /v1/form` in content-api.json.

## Checks for each commit

`phpcs --standard=Drupal` on changed files; module phpunit (unit + kernel + functional) via
the scratch site; for the portal, `wt-phpunit.sh <worktree-src> --filter X` only.
