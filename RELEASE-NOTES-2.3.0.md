# Quant 2.3.0 (Drupal 11)

## Upgrade notes

- **Quant Forms is opt-in for each Webform.** Nothing changes for your forms
  on upgrade. A Webform is managed by Drupal only after you tick "Take
  submissions through Quant Forms" in its settings (Settings > General >
  Third party settings > Quant Forms), or tick it on Configuration > Quant >
  Forms, or run `drush quant:webform-register ID --opt-in`. Then Drupal is
  the source of truth for that form: the next publish of its page replaces the
  Quant Forms settings made for that page in the Quant dashboard, and a
  Webform that has its own page posts to that page from every page that
  embeds it. Before you opt a form in, copy any notification addresses, Slack
  webhook and spam settings from the dashboard into the form's Quant Forms
  section. The update hook changes nothing.
- **The export fetch always sends the signed `quant-token`.** Before, it was
  sent only with content drafts on. The purger still records no traffic on
  sites with content drafts off, as before.
- **The API client sends nothing without a project token.** An unconnected
  site used to call the API with empty credentials.
- **Update 8105 (quant_search) clears the stored default search host.** A
  site that kept `https://search.quantgov.cloud` now reads the host at run
  time; a host that the site set itself stays. Run `drush updb`.

## Log in to Quant

Connect a site by approving it in the Quant dashboard and choosing its project,
instead of copying a token. The login is limited to that project, kept outside
configuration and refreshed on cron, and a write token rotated in the
dashboard is re-read automatically (after a 401, at most every 15 minutes,
and daily). A copy of the database does not publish with the original site's
login: the status report, the log and the API settings page say so and name
what differs. If it is the same site (for example after a database move or a
new hash salt), click "This is the same site" on the API settings page. Sites that publish several domains to several
projects, and sites in a subdirectory, keep using project tokens.
`drush quant:auth-status` shows the connection.

"Log out of Quant" ends the login in the Quant dashboard too, so the site's
token stops working at once (dashboards without this endpoint let it expire).
When Quant refuses to renew a login, the API settings page and the status
report say "The Quant login needs renewing", and the button reads "Log in to
Quant again". An unreachable Quant endpoint no longer breaks the API settings
page; the editor sees a plain message without the endpoint URL.

## Content changes

- A renamed page (for example a new Pathauto alias after a title change)
  keeps its old address working: Quant gets a 301 from the old alias to the
  new one. Sites with the Redirect module keep using its redirects.
- Deleted content no longer leaves `/node/N` or `/taxonomy/term/N`
  redirecting to a page that is gone.
- The shipped metadata views pass strict config schema checks.

## Quant Forms for Webform

Opted-in Webforms take submissions on the static site through Quant Forms,
including multi-page forms, conditions, composites and file uploads. Each
form's notification email, Slack webhook, honeypot, reCAPTCHA v2, spam check,
blocklists and confirmation are set in Drupal. The `quant_webform` README
lists what works on the static site and what does not.
Configuration > Quant > Forms explains the opt-in first and lists each
difference a form has on the static site. Saved Quant Forms settings now pass
config validation (the schema used the wrong key).

## Search pages

"Create a search page" makes a working search page from one screen, and
"Create from a View" proposes one from a View page. The search page config
schema now applies (it never did before). A second search page does not add a second search
box; after a box is placed, the wizard links to the seed page, because the
static pages show it only after they are published again. A site whose
`$settings['quant_api_dashboard_url']` names a QuantGov dashboard
(`dash.<env>.quantgov.cloud`) searches `search.<env>.quantgov.cloud`.
