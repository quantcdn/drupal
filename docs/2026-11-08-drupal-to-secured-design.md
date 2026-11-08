# Drupal site to secured on Quant: design

Date: 2026-11-08. Scope: module `quant` 2.x (Drupal 11) and the matching portal changes.
Base: `origin/2.x` at e7a1b95 (contains 2.2.0 Typesense search). 1.x is out of scope.

## 1. OAuth connect ("Log in to Quant")

### Flow

1. The editor opens Quant > API and selects the platform (QuantCDN or QuantGov). The
   platform sets the API endpoint (`api.quantcdn.io` / `api.quantgov.cloud`) and the dashboard
   (`dashboard.quantcdn.io` / `dash.quantgov.cloud`). A `$settings` override stays possible.
2. The editor clicks "Log in to Quant". The module sends the browser to
   `{dashboard}/oauth/authorize` with `client_id=drupal-quant`, `scope=full_access`, `state`,
   and an S256 PKCE challenge. The state and the verifier are in the Drupal session and are
   bound to the user id.
3. The editor approves in the dashboard (2FA, organisation choice: existing consent screen).
4. The callback (`/admin/config/development/quant/api/oauth/callback`) checks the state and
   exchanges the code at `{dashboard}/oauth/token`.
5. The module calls `GET /api/oauth/user` and lists the projects of each granted organisation
   (`GET /api/v2/organizations/{org}/projects`). The editor picks one project.
6. The module limits the OAuth token to that project (`POST /api/oauth/token/project`), then
   reads the project write token (`GET .../projects/{project}?with_token=1`).
7. The module stores the connection. All existing v1 content calls continue to use the
   `Quant-Customer` / `Quant-Project` / `Quant-Token` headers. The v1 content API does not
   accept the OAuth bearer, so this matches the WordPress plugin 2.0.x.

To pick another project, the editor disconnects and logs in again. A limited token cannot
widen (the portal returns 409), and this is the intended security property.

### Storage

- Tokens are never in exportable config. The OAuth access token, refresh token, expiry, and the
  project write token from step 6 go into the KeyValue collection `quant_api.oauth`.
- `quant_api.settings` keeps `auth_method` (`token` | `oauth`), the organisation and project
  machine names, the platform and the endpoint. Add a config schema for `quant_api.settings`
  (there is none today).
- A new service `quant_api.credentials` resolves the credentials in this order: `$settings`
  override, OAuth store (if `auth_method=oauth`), config `api_token`. `QuantClient`,
  `quant_search` and `SeedForm` read the token through this service, not from config.
- A site fingerprint (as in `ai_provider_quant_cloud`) blocks refresh on a copied database.

### Refresh

Port `AuthService` from `ai_provider_quant_cloud`: a lock for one refresh in flight, the 30 s /
300 s rotation-recovery rules of the portal, `invalid_grant` recorded as "reconnect needed",
cron refresh at half the lifetime, and a 60 s margin on use. The write token does not expire,
so a failed refresh never stops publishing. It only stops project listing and token re-sync.
The settings page shows the connection state and a "Reconnect" button.

### Fallback and limits

- The token form stays as "Connect with a project token" (CI, existing sites, settings.php).
- Multi-domain sites (`domain_config` overrides of `quant_api.settings`) stay on token
  connect in this release. The OAuth form shows a notice when overrides exist.
- Disconnect deletes the local tokens. The portal has no revoke endpoint (open question).
- Drush: `drush quant:auth-status` prints the state with no token values.

### Portal change (security-sensitive, review before use)

New client `drupal-quant` in `src/config/oauth.php`, modelled on `wordpress-quant` and
`scriptattest`: public, trusted, `require_pkce`, `allow_project_limit`, scoped tokens with
`projects:read`, 90d / 365d, `scoped_token_role_checked_at_use`. Redirect URIs: `http(s)://*`
and `http(s)://*:*` with the path `/admin/config/development/quant/api/oauth/callback`.
Tests: a client-flip feature test, the consent Authz test (PKCE refused without a challenge),
and the project-limit test (a limited token can read only its own project write token).

## 2. Quant Forms auto-registration

### How Quant Forms works (facts)

Quant Forms is a per-page switch, not a form object. A published route gets `form_enabled`
and `form_config`. The CDN takes a POST to that URL, stores the submission, sends the
notifications and serves the page again with a message inside `id="quant-form-result"`.
`POST /v1/form` sets the switch, and a content push (`POST /v1`) accepts the same two keys
inline. A later push without the keys keeps the published values.

### Design

- `quant_webform` adds `form_enabled` and `form_config` inline to the content push of every
  page that renders a Webform (the Webform canonical page and nodes or blocks that embed one).
  The inline push removes the "content must exist first" ordering problem.
- On Webform save, the module queues a re-push of the pages that show that Webform.
- "Register all" (a form action and `drush quant:webform-register`) queues every such page.
- Disable or delete of a Webform sends `form_enabled: false` (no delete endpoint exists).
- The module adds the `quant-form-result` container to each rendered Webform if the form does
  not already have the Quant result element.
- The mapper (`WebformQuantConfigMapper`, unit-tested) builds `form_config`:
  - `mandatory_fields`: required elements that have no conditional `#states`.
  - `remove_fields`: `form_build_id`, `form_token`, `form_id`, `op`, and the antibot / honeypot
    timestamp fields.
  - `honeypot_fields`: the Honeypot module field, if that module protects the form.
  - `success_message`: the Webform confirmation message (type "message" or "inline").
  - `notifications.email`: from the first enabled Webform email handler (to, cc, from,
    subject). Webform tokens are converted to Quant `{{field}}` tokens where a 1:1 mapping
    exists. Other tokens are removed, and the admin UI lists them.
  - `notifications.slack`: a per-site webhook from the module settings, kept in KeyValue,
    never in config.
  - `spam_threshold` / `spam_detect_action`: module settings (default 0, off).
  - `recaptcha_secret_key`: from the `recaptcha` module config, if that module protects the form.

### Support matrix

Supported: single-page forms; text, email, textarea, number, tel, url, date, select, radios,
checkboxes, hidden, likert; composite elements (address, name, contact) as flat keys such as
`address[city]`; file and managed_file elements (multipart POST, files kept by Quant);
client-side conditions (`#states`) because the static page keeps the Drupal JavaScript;
email and Slack notifications; honeypot; reCAPTCHA v2.

Partial: conditionally required fields (enforced in the browser only); email format, min, max
and pattern checks (browser only); confirmation "inline" (shown as the Quant message).

Not supported (the module shows a warning on the Webform and does not register the form):
multi-page wizards; AJAX submit; save draft; submission limits; confirmation types "page",
"url" and "modal"; handlers other than email (remote post, scripts); computed and server-side
elements; Turnstile and hCaptcha; per-submission tokens in messages. These need a server.

## 3. Quant search page from Drupal

Facts: the portal has no API to create dashboard search pages. `quant_search` already has a
Drupal search page entity. Drupal renders the page and pushes it to Quant. The page works on
Algolia and on Typesense (QuantGov).

Design (proposed reading of "creates the Quant search page"):
- A guided "Create a search page" form in `quant_search`: pick content types; the module
  proposes facets from the vocabularies attached to those types plus content type and
  language; default route `search`; enabled; publishes at once; optional "place the
  autocomplete block". It saves a `quant_search_page` entity and registers the facets.
- Fix the entity schema (only `id` and `label` are declared), the dead
  `search_page_repository` service, the `core/jquery.once` dependency, and the docs links.
- No new portal API, so no new security surface.

Portal UX pass on the dashboard search page wizard (separate branch):
- One screen with optional sections; default URL `/search`, slash normalised.
- Facet picker from `attributesForFaceting` instead of free text; filter help per backend.
- Fix the bug: the wizard saves design options to `search_options`, but the render reads
  `template_options`, so step 3 has no effect today.
- Add delete (with `authorize('edit_projects')`) and a link to the live page.
- Save requires `edit_projects` (the edit route allows `read_projects` today).

## 4. Stretch: Views to search page

Read a View's base table, bundle filter, exposed filters, sorts and pager. Propose a search page
draft (content types, facets from exposed taxonomy filters, results per page). The editor
reviews the draft in the form from item 3. Only after items 1 to 3.

## 5. Docs and skills

Update `quant-cms-integrations` (OAuth connect, forms, search page) and the Drupal search
docs page (Typesense on QuantGov, facet keys `{vocab}_{langcode}`). Also document
`POST /v1/form` in `content-api.json`. Done last, against the shipped behaviour.
