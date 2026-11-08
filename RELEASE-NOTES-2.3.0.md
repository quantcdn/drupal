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

## Log in to Quant

Connect a site by approving it in the Quant dashboard and choosing its project,
instead of copying a token. The login is limited to that project, kept outside
configuration and refreshed on cron, and a write token rotated in the
dashboard is re-read automatically. A copy of the database does not publish
with the original site's login. Sites that publish several domains to several
projects, and sites in a subdirectory, keep using project tokens.
`drush quant:auth-status` shows the connection.

## Quant Forms for Webform

Opted-in Webforms take submissions on the static site through Quant Forms,
including multi-page forms, conditions, composites and file uploads. Each
form's notification email, Slack webhook, honeypot, reCAPTCHA v2, spam check,
blocklists and confirmation are set in Drupal. The `quant_webform` README
lists what works on the static site and what does not.

## Search pages

"Create a search page" makes a working search page from one screen, and
"Create from a View" proposes one from a View page. The search page config
schema now applies (it never did before).
