<?php

namespace Drupal\quant_webform;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform\WebformInterface;

/**
 * The "Quant Forms" section of a Webform's settings.
 *
 * Every Quant Forms option for the form is here, so an opted-in form is
 * managed only in Drupal. Secrets (the Slack webhook, the reCAPTCHA secret)
 * are kept in KeyValue, never in config, and shown masked.
 */
class QuantFormsSettingsSection {

  use StringTranslationTrait;

  /**
   * Form values that are secrets; they are not third-party settings.
   */
  public const SECRET_PARENTS = ['quant_webform_secrets'];

  /**
   * Secret changes held from validation until the Webform is saved.
   *
   * @var array<string, array>
   */
  protected static array $pending = [];

  /**
   * The stored keys of the notification email.
   */
  protected const EMAIL_KEYS = ['mode', 'to', 'cc', 'from', 'reply_to', 'subject'];

  public function __construct(
    protected QuantFormsMapper $mapper,
    protected QuantFormsReport $report,
    protected EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * The section.
   */
  public function build(WebformInterface $webform): array {
    $settings = (array) $webform->getThirdPartySettings('quant_webform');
    $on = [':input[name="third_party_settings[quant_webform][enabled]"]' => ['checked' => TRUE]];
    return [
      '#type' => 'details',
      '#title' => $this->t('Quant Forms'),
      '#open' => TRUE,
      '#description' => $this->t('On the static site, Quant takes the submissions of this form, stores them and sends the notifications. When this form is on, these settings replace any Quant Forms settings made for its page in the Quant dashboard.'),
      '#element_validate' => [[$this, 'validate']],
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Take submissions through Quant Forms'),
        '#default_value' => QuantFormsRegistry::isOptedIn($webform),
      ],
      'email' => ['#states' => ['visible' => $on]] + $this->buildEmail($webform, $settings),
      'slack' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Send a Slack notification'),
        '#default_value' => $settings['slack'] ?? TRUE,
        '#states' => ['visible' => $on],
      ],
      'secrets' => ['#states' => ['visible' => $on]] + $this->buildSecrets($webform),
      'protection' => ['#states' => ['visible' => $on]] + $this->buildProtection($settings),
      'after' => ['#states' => ['visible' => $on]] + $this->buildAfterSubmit($webform, $settings),
      'unsupported' => $this->buildUnsupported(),
      'report' => $this->report->build($webform),
    ];
  }

  /**
   * Notification email: from the email handler, set here, or none.
   */
  protected function buildEmail(WebformInterface $webform, array $settings): array {
    $email = (array) ($settings['email'] ?? []);
    $derived = $this->mapper->emailNotification($webform) ?? [];
    $custom = [':input[name="third_party_settings[quant_webform][email][mode]"]' => ['value' => 'custom']];
    $field = fn ($key, $title, $description = NULL) => [
      '#type' => 'textfield',
      '#title' => $title,
      '#description' => $description,
      '#default_value' => $email[$key] ?? ($derived[$key] ?? ''),
      '#maxlength' => 256,
      '#states' => ['visible' => $custom],
    ];
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Notification email'),
      'mode' => [
        '#type' => 'radios',
        '#title' => $this->t('Send'),
        '#options' => [
          'handler' => $this->t('From the first email handler of this form (@to)', ['@to' => $derived['to'] ?? $this->t('none set')]),
          'custom' => $this->t('As set here'),
          'off' => $this->t('No email'),
        ],
        '#default_value' => $email['mode'] ?? 'handler',
      ],
      'to' => $field('to', $this->t('To'), $this->t('One or more addresses, separated by commas.')),
      'cc' => $field('cc', $this->t('Cc')),
      'from' => $field('from', $this->t('From')),
      'reply_to' => $field('reply_to', $this->t('Reply to'), $this->t('An address, or a field of the form such as {{email}}.')),
      'subject' => $field('subject', $this->t('Subject'), $this->t('Fields of the form can be used, for example {{email}}.')),
    ];
  }

  /**
   * The secrets: shown masked, kept in KeyValue.
   */
  protected function buildSecrets(WebformInterface $webform): array {
    $slack = $this->mapper->secret('slack_webhook', $webform->id());
    $recaptcha = $this->mapper->secret('recaptcha_secret', $webform->id());
    $parents = fn ($key) => array_merge(self::SECRET_PARENTS, [$key]);
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Secrets'),
      '#description' => $this->t('Kept outside configuration, so a configuration export never contains them. Leave a field empty to keep the saved value.'),
      'slack_webhook' => [
        '#type' => 'password',
        '#title' => $this->t('Slack webhook for this form'),
        '#description' => $slack !== ''
          ? $this->t('Saved: @masked', ['@masked' => self::mask($slack)])
          : $this->t('Optional. Without it, the site webhook on the Quant Forms page is used.'),
        '#parents' => $parents('slack_webhook'),
      ],
      'slack_webhook_remove' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the saved Slack webhook'),
        '#parents' => $parents('slack_webhook_remove'),
        '#access' => $slack !== '',
      ],
      'recaptcha_secret' => [
        '#type' => 'password',
        '#title' => $this->t('reCAPTCHA v2 secret key'),
        '#description' => $recaptcha !== '' ? $this->t('Saved: @masked', ['@masked' => self::mask($recaptcha)]) : NULL,
        '#parents' => $parents('recaptcha_secret'),
      ],
    ];
  }

  /**
   * Honeypot, reCAPTCHA, spam score and blocklists.
   */
  protected function buildProtection(array $settings): array {
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('Spam protection'),
      'honeypot' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Add a hidden honeypot field'),
        '#description' => $this->t('People do not see it; a bot that fills it in is refused.'),
        '#default_value' => $settings['honeypot'] ?? FALSE,
      ],
      'recaptcha' => [
        'enabled' => [
          '#type' => 'checkbox',
          '#title' => $this->t('reCAPTCHA v2 checkbox'),
          '#default_value' => $settings['recaptcha']['enabled'] ?? FALSE,
        ],
        'site_key' => [
          '#type' => 'textfield',
          '#title' => $this->t('reCAPTCHA v2 site key'),
          '#default_value' => $settings['recaptcha']['site_key'] ?? '',
          '#states' => ['visible' => [':input[name="third_party_settings[quant_webform][protection][recaptcha][enabled]"]' => ['checked' => TRUE]]],
        ],
      ],
      'spam_threshold' => [
        '#type' => 'select',
        '#title' => $this->t('AI spam check'),
        '#options' => ['' => $this->t('As on the Quant Forms page'), 0 => $this->t('Off')] + array_combine(range(1, 6), range(1, 6)),
        '#default_value' => (string) ($settings['spam_threshold'] ?? ''),
      ],
      'blocked_countries' => [
        '#type' => 'textfield',
        '#title' => $this->t('Refuse submissions from these countries'),
        '#description' => $this->t('Two-letter country codes, separated by commas, for example RU, KP.'),
        '#default_value' => implode(', ', (array) ($settings['blocked_countries'] ?? [])),
      ],
      'blocked_ips' => [
        '#type' => 'textarea',
        '#title' => $this->t('Refuse submissions from these IP addresses'),
        '#description' => $this->t('One address per line.'),
        '#rows' => 2,
        '#default_value' => implode("\n", (array) ($settings['blocked_ips'] ?? [])),
      ],
    ];
  }

  /**
   * What the visitor sees after a submit.
   */
  protected function buildAfterSubmit(WebformInterface $webform, array $settings): array {
    $confirmation = (array) ($settings['confirmation'] ?? []);
    return [
      '#type' => 'fieldset',
      '#title' => $this->t('After a submit'),
      'confirmation_type' => [
        '#type' => 'radios',
        '#title' => $this->t('Show'),
        '#options' => [
          'webform' => $this->t("As this form's confirmation settings say (@type)", ['@type' => $webform->getSetting('confirmation_type')]),
          'message' => $this->t('A message in place of the form'),
          'url' => $this->t('Another page'),
        ],
        '#default_value' => $confirmation['type'] ?? 'webform',
      ],
      'confirmation_url' => [
        '#type' => 'textfield',
        '#title' => $this->t('Page to show'),
        '#description' => $this->t('A path on this site such as /thank-you, or an https address.'),
        '#default_value' => $confirmation['url'] ?? '',
        '#states' => ['visible' => [':input[name="third_party_settings[quant_webform][after][confirmation_type]"]' => ['value' => 'url']]],
      ],
      'success_message' => [
        '#type' => 'textarea',
        '#title' => $this->t('Message'),
        '#description' => $this->t('Leave empty to use the confirmation message without its tokens. Without JavaScript Quant shows at most 256 characters of plain text.'),
        '#default_value' => $settings['success_message'] ?? '',
        '#rows' => 3,
      ],
    ];
  }

  /**
   * Webform features that Quant Forms cannot do, greyed out with the reason.
   */
  protected function buildUnsupported(): array {
    $rows = [
      'webhook' => [
        $this->t('Webhooks other than Slack'),
        $this->t('Quant Forms sends email and Slack only.'),
      ],
      'captcha' => [
        $this->t('Turnstile, hCaptcha or an image CAPTCHA'),
        $this->t('Quant checks reCAPTCHA v2 only.'),
      ],
      'limits' => [
        $this->t('Submission limits'),
        $this->t('Quant does not count submissions per form.'),
      ],
      'drafts' => [
        $this->t('Save draft'),
        $this->t('Drafts are kept by Drupal, which the static site does not reach.'),
      ],
      'attachments' => [
        $this->t('Uploaded files attached to the email'),
        $this->t('Quant stores the files with the submission; the email lists the fields.'),
      ],
      'tokens' => [
        $this->t('Submission tokens such as the serial number'),
        $this->t('No server renders them on a static page.'),
      ],
    ];
    $section = [
      '#type' => 'fieldset',
      '#title' => $this->t('Not available with Quant Forms'),
    ];
    foreach ($rows as $key => [$title, $reason]) {
      $section[$key] = [
        '#type' => 'checkbox',
        '#title' => $title,
        '#description' => $reason,
        '#disabled' => TRUE,
        '#value' => FALSE,
        '#parents' => ['quant_webform_unsupported', $key],
      ];
    }
    return $section;
  }

  /**
   * Validates the section and flattens it into the stored settings.
   */
  public function validate(array &$element, FormStateInterface $form_state): void {
    $base = ['third_party_settings', 'quant_webform'];
    $values = (array) $form_state->getValue($base);
    /** @var \Drupal\webform\WebformInterface $webform */
    $webform = $form_state->getFormObject()->getEntity();
    // Secrets are checked and held whether or not the form is ticked, so
    // "Remove the saved Slack webhook" always works.
    $this->validateSecrets($element, $form_state);
    if (!empty($values['enabled'])) {
      $this->validateEmail($values, $element, $form_state);
      $this->validateProtection($values, $element, $form_state, $webform);
      $this->validateAfter($values, $element, $form_state);
    }
    $form_state->setValue($base, (empty($values['enabled']) ? ['enabled' => FALSE] : []) + $this->flatten($values));
    // Stored by hook_webform_presave(), so only when the Webform is saved.
    self::$pending[$webform->id()] = (array) $form_state->getValue(self::SECRET_PARENTS);
  }

  /**
   * Stores the secrets held for a Webform that is being saved.
   */
  public function storePending(WebformInterface $webform): void {
    if (!isset(self::$pending[$webform->id()])) {
      return;
    }
    $secrets = self::$pending[$webform->id()];
    unset(self::$pending[$webform->id()]);
    if (!empty($secrets['slack_webhook_remove'])) {
      $this->mapper->setSecret('slack_webhook', $webform->id(), '');
    }
    elseif (trim((string) ($secrets['slack_webhook'] ?? '')) !== '') {
      $this->mapper->setSecret('slack_webhook', $webform->id(), trim($secrets['slack_webhook']));
    }
    if (trim((string) ($secrets['recaptcha_secret'] ?? '')) !== '') {
      $this->mapper->setSecret('recaptcha_secret', $webform->id(), trim($secrets['recaptcha_secret']));
    }
  }

  /**
   * Deletes the secrets of a Webform (when it is deleted).
   */
  public function deleteSecrets(string $webform_id): void {
    $this->mapper->setSecret('slack_webhook', $webform_id, '');
    $this->mapper->setSecret('recaptcha_secret', $webform_id, '');
  }

  /**
   * The stored shape of the section's values.
   */
  protected function flatten(array $values): array {
    $protection = (array) ($values['protection'] ?? []);
    $after = (array) ($values['after'] ?? []);
    return [
      'enabled' => (bool) ($values['enabled'] ?? FALSE),
      'email' => array_map('trim', array_intersect_key((array) ($values['email'] ?? []), array_flip(self::EMAIL_KEYS))),
      'slack' => (bool) ($values['slack'] ?? TRUE),
      'honeypot' => (bool) ($protection['honeypot'] ?? FALSE),
      'recaptcha' => [
        'enabled' => (bool) ($protection['recaptcha']['enabled'] ?? FALSE),
        'site_key' => trim((string) ($protection['recaptcha']['site_key'] ?? '')),
      ],
      'spam_threshold' => (string) ($protection['spam_threshold'] ?? ''),
      'blocked_countries' => self::split((string) ($protection['blocked_countries'] ?? ''), '/[\s,]+/', 'strtoupper'),
      'blocked_ips' => self::split((string) ($protection['blocked_ips'] ?? ''), '/\s+/'),
      'confirmation' => [
        'type' => (string) ($after['confirmation_type'] ?? 'webform'),
        'url' => trim((string) ($after['confirmation_url'] ?? '')),
      ],
      'success_message' => trim((string) ($after['success_message'] ?? '')),
    ];
  }

  /**
   * Checks the email addresses of a custom email.
   */
  protected function validateEmail(array $values, array $element, FormStateInterface $form_state): void {
    $email = (array) ($values['email'] ?? []);
    if (($email['mode'] ?? 'handler') !== 'custom') {
      return;
    }
    $checks = ['to' => TRUE, 'cc' => FALSE, 'from' => FALSE];
    foreach ($checks as $key => $required) {
      $value = trim((string) ($email[$key] ?? ''));
      if (($required && $value === '') || !$this->isEmailList($value, $key !== 'from')) {
        $form_state->setError($element['email'][$key], $this->t('@field: enter valid email addresses@list.', [
          '@field' => $element['email'][$key]['#title'],
          '@list' => $key === 'from' ? '' : $this->t(', separated by commas'),
        ]));
      }
    }
    $reply = trim((string) ($email['reply_to'] ?? ''));
    if ($reply !== '' && !preg_match('/^\{\{[a-z0-9_]+\}\}$/', $reply) && !$this->isEmailList($reply, FALSE)) {
      $form_state->setError($element['email']['reply_to'], $this->t('Reply to: enter an email address or a field such as {{email}}.'));
    }
  }

  /**
   * Whether a value is empty, one address, or (when allowed) a list.
   */
  protected function isEmailList(string $value, bool $list): bool {
    if ($value === '') {
      return TRUE;
    }
    $parts = array_map('trim', explode(',', $value));
    if (!$list && count($parts) > 1) {
      return FALSE;
    }
    foreach ($parts as $part) {
      if (!$this->emailValidator->isValid($part)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The Slack webhook must be an https URL on hooks.slack.com.
   */
  protected function validateSecrets(array $element, FormStateInterface $form_state): void {
    $webhook = trim((string) $form_state->getValue(array_merge(self::SECRET_PARENTS, ['slack_webhook'])));
    if ($webhook !== '' && !self::isSlackWebhook($webhook)) {
      $form_state->setError($element['secrets']['slack_webhook'], $this->t('Enter a Slack incoming webhook URL. It starts with https://hooks.slack.com/.'));
    }
  }

  /**
   * Whether a URL is a Slack incoming webhook (https, host hooks.slack.com).
   */
  public static function isSlackWebhook(string $url): bool {
    $parts = parse_url($url);
    return ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'hooks.slack.com'
      && !isset($parts['user']) && !isset($parts['port']) && str_starts_with((string) ($parts['path'] ?? ''), '/services/');
  }

  /**
   * Checks reCAPTCHA, the country codes and the IP addresses.
   */
  protected function validateProtection(array $values, array $element, FormStateInterface $form_state, WebformInterface $webform): void {
    $protection = (array) ($values['protection'] ?? []);
    if (!empty($protection['recaptcha']['enabled'])) {
      if (!preg_match('/^[A-Za-z0-9_-]{20,64}$/', trim((string) ($protection['recaptcha']['site_key'] ?? '')))) {
        $form_state->setError($element['protection']['recaptcha']['site_key'], $this->t('Enter the reCAPTCHA v2 site key.'));
      }
      // Without the secret the widget shows but Quant checks nothing.
      $new_secret = trim((string) $form_state->getValue(array_merge(self::SECRET_PARENTS, ['recaptcha_secret'])));
      if ($new_secret === '' && $this->mapper->secret('recaptcha_secret', $webform->id()) === '') {
        $form_state->setError($element['secrets']['recaptcha_secret'], $this->t('Enter the reCAPTCHA v2 secret key. Without it Quant cannot check the reCAPTCHA.'));
      }
    }
    foreach (self::split((string) ($protection['blocked_countries'] ?? ''), '/[\s,]+/', 'strtoupper') as $code) {
      if (!preg_match('/^[A-Z]{2}$/', $code)) {
        $form_state->setError($element['protection']['blocked_countries'], $this->t('@code is not a two-letter country code.', ['@code' => $code]));
      }
    }
    foreach (self::split((string) ($protection['blocked_ips'] ?? ''), '/\s+/') as $ip) {
      if (filter_var($ip, FILTER_VALIDATE_IP) === FALSE) {
        $form_state->setError($element['protection']['blocked_ips'], $this->t('@ip is not an IP address.', ['@ip' => $ip]));
      }
    }
  }

  /**
   * Checks the page to show after a submit.
   */
  protected function validateAfter(array $values, array $element, FormStateInterface $form_state): void {
    $after = (array) ($values['after'] ?? []);
    $url = trim((string) ($after['confirmation_url'] ?? ''));
    if (($after['confirmation_type'] ?? '') === 'url' && !preg_match('#^(/(?!/)|https://)\S*$#', $url)) {
      $form_state->setError($element['after']['confirmation_url'], $this->t('Enter a path on this site, such as /thank-you, or an https address.'));
    }
  }

  /**
   * A secret with all but its last four characters hidden.
   */
  public static function mask(string $secret): string {
    return str_repeat('•', 8) . substr($secret, -4);
  }

  /**
   * Splits a list and drops empty items.
   */
  protected static function split(string $value, string $pattern, ?callable $map = NULL): array {
    $items = array_values(array_filter(preg_split($pattern, trim($value)) ?: [], fn ($item) => $item !== ''));
    return $map ? array_map($map, $items) : $items;
  }

}
