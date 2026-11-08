# Quant webform

Lets a Webform take submissions on the static Quant site. Quant Forms stores
each submission and sends the email and Slack notifications.

## How it works

- When Quant publishes a page that shows a Webform, the push carries the Quant
  Forms settings of that page (`form_enabled`, `form_config`). Quant then takes
  POST requests to that page.
- Saving a Webform publishes the page that takes its submissions: the Webform's
  own page, or, for a Webform without one, every published node whose Webform
  field references it.
- Closing or deleting a Webform turns Quant Forms off on its pages. The
  submissions stay in Quant.
- **Register all forms** (Configuration > Quant > Forms) queues the pages of
  every Webform. `drush quant:webform-register [id]` publishes them at once.
- The live Drupal site is unchanged. Only the render that Quant publishes (the
  request with the signed `quant-token` header) gets the static form.

## Settings

- Per Webform (Settings > General > Third party settings > Quant Forms): take
  submissions through Quant Forms (default on), a static message after a submit,
  and Slack on or off.
- Site wide (Configuration > Quant > Forms): the AI spam check (off, or a score
  from 1 to 6), what to do with spam, and a Slack incoming webhook. The webhook
  is kept outside config, so a config export never contains it.

## What is supported

| Webform feature | On the static site |
|---|---|
| Single-page forms | Supported. |
| A Webform in a block, a footer or a node (for example a newsletter on every page) | Supported when the Webform has its own page: on the static site it posts to that page from everywhere, so it has one Quant Forms config. A Webform without its own page posts to the page that shows it. |
| Multi-page wizards | Supported. All pages are in the page; JavaScript shows one step at a time and checks each step before the next. |
| Preview (review) step | Supported. The browser builds the review from the answers. |
| Conditional logic (`#states`) | Supported in the browser. Hidden fields are not checked. Pages hidden by conditions are skipped. |
| Required fields | Checked in the browser. Quant also checks fields that are always required. |
| Conditionally required fields | Checked in the browser only. |
| Email, number, pattern, min and max checks | Checked in the browser only (HTML validation). |
| Text, email, tel, url, number, date, textarea, select, radios, checkboxes, checkbox, hidden, likert | Supported. |
| Composite elements (address, name, contact, custom composite) | Supported. Each part posts as `key[part]`. Quant checks parts that are marked required. |
| File uploads (managed_file and the image, document, audio and video variants) | Supported. Quant stores the files with the submission. With JavaScript, each file of a multi-file field is kept (`attachments`, `attachments_1`, ...). Without JavaScript only the last file is kept. |
| Email handler | Supported. The first enabled email handler becomes the Quant email (to, cc, from, subject, reply-to). `[webform_submission:values:KEY]` becomes `{{KEY}}`; other tokens are removed. Addresses that contain tokens are left out. |
| Slack | Supported, with the site webhook. |
| Honeypot module | Supported (its field is a Quant honeypot field). |
| reCAPTCHA v2 (CAPTCHA module with reCAPTCHA) | Supported. |
| AI spam scoring | Supported (site setting). |
| Confirmation: message, inline, page | Shown in place of the form. |
| Confirmation: url, url_message | Redirects in the browser after the submit. |
| Confirmation: modal | Shown in place of the form. |
| Tokens in the confirmation message | Removed. Set a static message per form. |
| Without JavaScript | All pages show and the form posts once; Quant shows its message in the result area. Quant shows at most 256 characters of plain text. |

## What is not supported

The report on each Webform and on the Forms settings page lists these.

| Webform feature | Why |
|---|---|
| AJAX submit ("Use Ajax") | The static page would post to Drupal. The form is not registered until AJAX is off. |
| Save draft | Needs Drupal. The button is removed on the static site. |
| Submission limits | Not enforced: Quant does not count submissions per form. |
| Handlers other than email (remote post, scripts, and so on) | They run in Drupal. Quant stores the submission instead. |
| Computed elements | They compute on the server. |
| Turnstile, hCaptcha, image CAPTCHA | Quant checks reCAPTCHA v2 only. |
| Submission tokens such as `[webform_submission:serial]` | Not available on static pages: no server renders them. They are removed from messages, so "Your reference is SR-[webform_submission:serial]" becomes "Your reference is SR-". Set a static message in the form's Quant Forms settings. |
| Two Webforms without their own pages on one page | Quant has one form config per page. The first is registered and a warning is logged. |
