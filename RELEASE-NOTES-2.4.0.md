# Quant 2.4.0 (Drupal 11)

## Upgrade notes

- **Nothing changes until you pick alert channels.** A Webform without
  channels publishes the same Quant Forms config as in 2.3.3: the same email
  and the same Slack webhook.
- **Run `drush updb` (or update.php) after the upgrade.** The post update
  `quant_webform_post_update_alert_channels` changes no data. It rebuilds the
  service container, which needs the new alert channel service. Without it the
  Webform settings pages can fail until the caches are rebuilt.
- **Sites that logged in to Quant before 2.4 need "Reconnect to Quant" once.**
  An older login cannot read the alert channels. The Quant Forms section of
  the Webform then shows "Reconnect to Quant"; follow the link and choose the
  same project. Publishing is not affected.
- **The channel list is read only for a form that is on or has channels.**
  A form that does not take submissions through Quant Forms says: tick "Take
  submissions through Quant Forms" and save to pick alert channels.
- **Sites connected with a project token** cannot read the channels. The
  section shows "Connect to Quant". Channels that a form already has stay and
  still publish.

## Alert channels for Webforms

Each Webform's Quant Forms section has an "Alert channels" list: the email
lists and Slack destinations of your Quant organisation, read from the Quant
dashboard with the login. Tick "Use" to send the form's notifications to a
channel, and "Cc" to send an email channel as cc.

- With a channel picked, the form publishes in channel mode: Quant sends to
  the channels, and the form's Slack webhook and the email recipients set in
  Drupal are not used. The email subject, sender name and reply-to still
  apply (from the email handler, or as set in Drupal; with "No email", the
  default subject).
- A save that stores a channel deletes the form's own Slack webhook. The site
  webhook on Configuration > Quant > Forms stays.
- When the list cannot be read, the section says "The alert channels are not
  available now." and a save keeps the picked channels as they are.
- A picked channel that the organisation no longer has shows as "Channel no
  longer exists". It stays until you untick it.
- Channel addresses and webhooks stay in the Quant dashboard. The form stores
  only the channel IDs; the short hint shown beside a channel is not stored.

## Keep submissions for

"Keep submissions for" sets how long Quant keeps the form's submissions: the
organisation default, 30 days, 90 days, 1 year, 2 years, 7 years, or forever.
