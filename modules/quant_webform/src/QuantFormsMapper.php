<?php

namespace Drupal\quant_webform;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform\Plugin\WebformElement\WebformCompositeBase;
use Drupal\webform\Plugin\WebformElementManagerInterface;
use Drupal\webform\WebformInterface;

/**
 * Maps a Webform to a Quant Forms form_config and a support report.
 *
 * Quant Forms checks only presence (mandatory fields) and absence (honeypot
 * fields), sends email and Slack notifications, and shows one message. All
 * other Webform behaviour runs in the browser or not at all. The report says
 * which.
 */
class QuantFormsMapper {

  use StringTranslationTrait;

  /**
   * The KeyValue collection of the module's secrets (never config).
   */
  public const SECRETS = 'quant_webform.secrets';

  /**
   * Quant limits each message to 256 characters.
   */
  public const MESSAGE_MAX = 256;

  /**
   * Fields that Drupal adds and that are not submission data.
   */
  public const DRUPAL_FIELDS = ['form_build_id', 'form_token', 'form_id', 'op', 'honeypot_time', 'antibot_key'];

  /**
   * Element types that only display, or compute a value on the server.
   */
  protected const SERVER_COMPUTED = ['webform_computed_token', 'webform_computed_twig'];

  /**
   * Element types that hold other elements and submit nothing themselves.
   */
  protected const CONTAINERS = [
    'webform_wizard_page', 'container', 'fieldset', 'details', 'webform_section',
    'webform_flexbox', 'webform_card', 'item', 'webform_markup', 'webform_message',
    'processed_text', 'webform_actions', 'webform_horizontal_rule', 'label',
  ];

  /**
   * Element types that submit no single name the edge can check.
   */
  protected const NO_SINGLE_NAME = [
    'checkboxes', 'managed_file', 'webform_image_file', 'webform_document_file',
    'webform_audio_file', 'webform_video_file', 'captcha',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ModuleHandlerInterface $moduleHandler,
    protected KeyValueFactoryInterface $keyValue,
    protected WebformElementManagerInterface $elementManager,
  ) {}

  /**
   * The report and the form_config of one Webform.
   *
   * @return array
   *   - supported: whether the page can take submissions through Quant.
   *   - blocking: why it cannot (translated strings).
   *   - notes: what works differently on the static site.
   *   - config: the form_config to send.
   */
  public function map(WebformInterface $webform, string $url): array {
    $report = ['blocking' => [], 'notes' => []];
    $this->checkSettings($webform, $report);
    $elements = $webform->getElementsInitializedAndFlattened();
    $this->checkElements($elements, $report);
    $report['supported'] = $report['blocking'] === [];
    $report['config'] = $this->buildConfig($webform, $elements, $url, $report);
    return $report;
  }

  /**
   * Checks the form settings.
   */
  protected function checkSettings(WebformInterface $webform, array &$report): void {
    if ($webform->getSetting('ajax')) {
      $report['blocking'][] = $this->t('AJAX submit is on. The static page would post to Drupal. Turn off "Use Ajax" in the form settings.');
    }
    if ($webform->hasWizardPages()) {
      $report['notes'][] = $this->t('Wizard pages run in the browser. Each page is checked before the next one; the review step is built in the browser.');
    }
    if ($webform->getSetting('draft') && $webform->getSetting('draft') !== WebformInterface::DRAFT_NONE) {
      $report['notes'][] = $this->t('Save draft is not available on the static site.');
    }
    if ($webform->getSetting('limit_total') || $webform->getSetting('limit_user')) {
      $report['notes'][] = $this->t('Submission limits are not enforced on the static site.');
    }
    $this->checkConfirmation($webform, $report);
    $this->checkHandlers($webform, $report);
  }

  /**
   * Checks the confirmation type.
   */
  protected function checkConfirmation(WebformInterface $webform, array &$report): void {
    $type = (string) $webform->getSetting('confirmation_type');
    if ($type === 'modal') {
      $report['notes'][] = $this->t('The modal confirmation shows in place of the form.');
    }
    if ($type === 'page') {
      $report['notes'][] = $this->t('The confirmation page shows in place of the form, at the same address.');
    }
    if (preg_match('/\[[a-z_]+:[^\]]+\]/', (string) $webform->getSetting('confirmation_message'))) {
      $report['notes'][] = $this->t('Tokens in the confirmation message (for example a reference number) cannot run on a static site and are removed. Set a static message in the Quant Forms settings of this form.');
    }
  }

  /**
   * Checks the handlers: only email runs, through Quant.
   */
  protected function checkHandlers(WebformInterface $webform, array &$report): void {
    foreach ($webform->getHandlers() as $handler) {
      if (!$handler->isEnabled() || $handler->getPluginId() === 'email') {
        continue;
      }
      $report['notes'][] = $this->t('The handler %handler does not run on the static site. Quant stores the submission and sends the notifications.', ['%handler' => $handler->label()]);
    }
  }

  /**
   * Checks the element types.
   */
  protected function checkElements(array $elements, array &$report): void {
    foreach ($elements as $key => $element) {
      $type = (string) ($element['#type'] ?? '');
      if (in_array($type, self::SERVER_COMPUTED, TRUE)) {
        $report['notes'][] = $this->t('The computed element %key is not computed on the static site.', ['%key' => $key]);
      }
      if ($type === 'captcha' && !$this->isRecaptcha($element)) {
        $report['notes'][] = $this->t('Quant checks reCAPTCHA v2 only. The CAPTCHA %key is not checked on the static site.', ['%key' => $key]);
      }
      if (!empty($element['#states']) && !empty($element['#required'])) {
        $report['notes'][] = $this->t('%key is required only under conditions. The browser checks it; Quant does not.', ['%key' => $key]);
      }
    }
  }

  /**
   * Whether a captcha element uses reCAPTCHA.
   */
  protected function isRecaptcha(array $element): bool {
    return stripos((string) ($element['#captcha_type'] ?? ''), 'recaptcha') !== FALSE;
  }

  /**
   * Builds the form_config.
   */
  protected function buildConfig(WebformInterface $webform, array $elements, string $url, array $report): array {
    $settings = $this->configFactory->get('quant_webform.settings');
    $config = [
      'target_url' => $url,
      'mandatory_fields' => $this->mandatoryFields($elements),
      'remove_fields' => $this->removeFields($elements),
      'honeypot_fields' => $this->honeypotFields($webform),
      'country_is_not' => [],
      'ip_is_not' => [],
      'spam_threshold' => (int) $settings->get('spam_threshold'),
      'spam_detect_action' => $settings->get('spam_detect_action') === 'keep' ? 'keep' : 'discard',
      'success_message' => $this->successMessage($webform),
      'error_message_mandatory' => self::limit((string) $this->t('Please complete all required fields.')),
      'error_message_generic' => self::limit((string) $this->t('Your submission could not be sent. Please try again.')),
      'notification_settings' => [
        'org_timezone' => (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC'),
        'org_name' => (string) $this->configFactory->get('system.site')->get('name'),
      ],
      'notifications' => $this->notifications($webform),
    ];
    $secret = $this->recaptchaSecret($elements);
    if ($secret !== '') {
      $config['recaptcha_secret_key'] = $secret;
    }
    return $config;
  }

  /**
   * The submitted names that Quant must find in every submission.
   *
   * Only elements that are always required: no #states on the element or on
   * any parent. Checkboxes and files submit no single name, so the browser
   * checks them.
   */
  public function mandatoryFields(array $elements): array {
    $fields = [];
    foreach ($elements as $key => $element) {
      if (empty($element['#required']) || $this->isConditional($element, $elements)) {
        continue;
      }
      $fields = array_merge($fields, $this->submittedNames((string) $key, $element));
    }
    return array_values(array_unique($fields));
  }

  /**
   * Whether the element or one of its parents has #states.
   */
  protected function isConditional(array $element, array $elements): bool {
    foreach ($element['#webform_parents'] ?? [] as $parent) {
      if (!empty($elements[$parent]['#states'])) {
        return TRUE;
      }
    }
    return !empty($element['#states']);
  }

  /**
   * The names a required element submits, as the edge stores them.
   */
  protected function submittedNames(string $key, array $element): array {
    $type = (string) ($element['#type'] ?? '');
    if (in_array($type, self::CONTAINERS, TRUE) || in_array($type, self::NO_SINGLE_NAME, TRUE)) {
      return [];
    }
    if (!empty($element['#multiple']) && $type !== 'select') {
      return [];
    }
    $plugin = $this->elementManager->getElementInstance($element);
    if ($plugin instanceof WebformCompositeBase) {
      return $this->compositeNames($key, $element, $plugin);
    }
    return [$key];
  }

  /**
   * The explicitly required sub-fields of a composite: key[sub].
   *
   * A composite posts key[sub], never key. Which sub-fields a required
   * address or name needs depends on the composite, so only sub-fields that
   * are marked required count: '#element' entries of a custom composite, or
   * '#SUB__required' on the others. The browser checks the rest.
   */
  protected function compositeNames(string $key, array $element, WebformCompositeBase $plugin): array {
    $names = [];
    if (isset($element['#element']) && is_array($element['#element'])) {
      foreach ($element['#element'] as $sub => $sub_element) {
        if (!empty($sub_element['#required']) && empty($sub_element['#states'])) {
          $names[] = $key . '[' . $sub . ']';
        }
      }
      return $names;
    }
    foreach (array_keys($plugin->getCompositeElements()) as $sub) {
      if (!empty($element['#' . $sub . '__required']) && ($element['#' . $sub . '__access'] ?? TRUE) !== FALSE) {
        $names[] = $key . '[' . $sub . ']';
      }
    }
    return $names;
  }

  /**
   * Drupal's own fields, and the hidden file id fields.
   */
  protected function removeFields(array $elements): array {
    $fields = self::DRUPAL_FIELDS;
    foreach ($elements as $key => $element) {
      if (str_ends_with((string) ($element['#type'] ?? ''), 'file')) {
        $fields[] = $key . '[fids]';
      }
    }
    return $fields;
  }

  /**
   * The Honeypot module field, when Honeypot protects the form.
   */
  protected function honeypotFields(WebformInterface $webform): array {
    if (!$this->moduleHandler->moduleExists('honeypot')) {
      return [];
    }
    $honeypot = $this->configFactory->get('honeypot.settings');
    $protected = $webform->getThirdPartySetting('honeypot', 'honeypot') || $honeypot->get('protect_all_forms');
    return $protected ? [(string) ($honeypot->get('element_name') ?: 'url')] : [];
  }

  /**
   * The reCAPTCHA secret, when a reCAPTCHA element protects the form.
   */
  protected function recaptchaSecret(array $elements): string {
    foreach ($elements as $element) {
      if (($element['#type'] ?? '') === 'captcha' && $this->isRecaptcha($element)) {
        return (string) $this->configFactory->get('recaptcha.settings')->get('secret_key');
      }
    }
    return '';
  }

  /**
   * The message after a submit, without Webform tokens.
   */
  public function successMessage(WebformInterface $webform): string {
    $override = (string) $webform->getThirdPartySetting('quant_webform', 'success_message', '');
    $message = $override !== '' ? $override : (string) $webform->getSetting('confirmation_message');
    // Quant inserts the message as markup and cuts it at 256 characters, so
    // send plain text: a cut never breaks a tag.
    $message = preg_replace('#</(p|div|li|h[1-6])>|<br\s*/?>#i', ' ', self::stripTokens($message));
    $message = trim(preg_replace('/\s+/', ' ', strip_tags($message)));
    return self::limit($message !== '' ? $message : (string) $this->t('Thank you. Your submission has been received.'));
  }

  /**
   * The email and Slack notifications.
   */
  protected function notifications(WebformInterface $webform): array {
    $notifications = [];
    $email = $this->emailNotification($webform);
    if ($email !== NULL) {
      $notifications['email'] = $email;
    }
    $webhook = (string) $this->keyValue->get(self::SECRETS)->get('slack_webhook', '');
    if ($webhook !== '' && $webform->getThirdPartySetting('quant_webform', 'slack', TRUE)) {
      $notifications['slack'] = ['webhook' => $webhook, 'enabled' => TRUE];
    }
    return $notifications;
  }

  /**
   * The first enabled email handler that runs on a completed submission.
   */
  protected function emailNotification(WebformInterface $webform): ?array {
    foreach ($webform->getHandlers('email', TRUE) as $handler) {
      $settings = $handler->getConfiguration()['settings'] ?? [];
      if (!in_array('completed', (array) ($settings['states'] ?? ['completed']), TRUE)) {
        continue;
      }
      $to = $this->address((string) ($settings['to_mail'] ?? ''));
      if ($to === '') {
        continue;
      }
      $email = [
        'enabled' => TRUE,
        'to' => $to,
        'subject' => self::limit(self::convertTokens((string) ($settings['subject'] ?? '')) ?: (string) $this->t('New submission: @form', ['@form' => $webform->label()])),
        'options' => ['text_only' => empty($settings['html']), 'include_results' => TRUE],
      ];
      foreach (['cc' => 'cc_mail', 'from' => 'from_mail'] as $quant => $drupal) {
        $value = $this->address((string) ($settings[$drupal] ?? ''));
        if ($value !== '') {
          $email[$quant] = $value;
        }
      }
      $reply_to = self::convertTokens((string) ($settings['reply_to'] ?? ''));
      if ($reply_to !== '') {
        $email['reply_to'] = $reply_to;
      }
      return $email;
    }
    return NULL;
  }

  /**
   * An address setting: "default" is the site mail; tokens are dropped.
   */
  protected function address(string $value): string {
    if ($value === '_default' || $value === 'default') {
      return (string) $this->configFactory->get('system.site')->get('mail');
    }
    return str_contains($value, '[') ? '' : trim($value);
  }

  /**
   * Converts [webform_submission:values:KEY(:raw)] to {{KEY}}, drops others.
   */
  public static function convertTokens(string $text): string {
    $text = preg_replace('/\[webform_submission:values:([a-z0-9_]+)(?::raw|:value)?\]/', '{{$1}}', $text);
    return trim(preg_replace('/\s{2,}/', ' ', self::stripTokens($text)));
  }

  /**
   * Removes every [type:name] token.
   */
  public static function stripTokens(string $text): string {
    return trim(preg_replace('/\[[a-z_]+:[^\]]+\]/', '', $text));
  }

  /**
   * Cuts a message to the Quant limit at a word boundary.
   */
  public static function limit(string $text): string {
    return Unicode::truncate(trim($text), self::MESSAGE_MAX, TRUE, TRUE);
  }

}
