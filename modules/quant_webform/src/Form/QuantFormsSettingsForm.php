<?php

namespace Drupal\quant_webform\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant_webform\QuantFormsMapper;
use Drupal\quant_webform\QuantFormsRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant Forms: spam, Slack and "Register all forms".
 */
class QuantFormsSettingsForm extends ConfigFormBase {

  /**
   * The Webform storage.
   *
   * @var \Drupal\Core\Config\Entity\ConfigEntityStorageInterface
   */
  protected $webformStorage;

  /**
   * The mapper.
   *
   * @var \Drupal\quant_webform\QuantFormsMapper
   */
  protected $mapper;

  /**
   * The registrar.
   *
   * @var \Drupal\quant_webform\QuantFormsRegistrar
   */
  protected $registrar;

  /**
   * The secrets store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $secrets;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->webformStorage = $container->get('entity_type.manager')->getStorage('webform');
    $instance->mapper = $container->get('quant_webform.mapper');
    $instance->registrar = $container->get('quant_webform.registrar');
    $instance->secrets = $container->get('keyvalue')->get(QuantFormsMapper::SECRETS);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_webform_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['quant_webform.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('quant_webform.settings');
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Quant Forms is off for every form until you tick it. A ticked form takes its submissions on the static site, and replaces Quant Forms settings made by hand in the dashboard for its page. Changes to the spam and Slack settings apply after "Register the ticked forms".') . '</p>',
    ];
    $form['forms'] = $this->buildFormsTable();
    $form['register'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register the ticked forms'),
      '#submit' => ['::submitRegisterAll'],
    ];
    $form['spam'] = [
      '#type' => 'details',
      '#title' => $this->t('Spam'),
      '#open' => TRUE,
      'spam_threshold' => [
        '#type' => 'select',
        '#title' => $this->t('AI spam check'),
        '#options' => [0 => $this->t('Off')] + array_combine(range(1, 6), range(1, 6)),
        '#default_value' => (int) $config->get('spam_threshold'),
        '#description' => $this->t('Quant scores each submission from 0 to 6. A submission at or above this score counts as spam. 3 suits most sites.'),
      ],
      'spam_detect_action' => [
        '#type' => 'radios',
        '#title' => $this->t('What to do with spam'),
        '#options' => ['discard' => $this->t('Discard it'), 'keep' => $this->t('Keep it, marked as spam')],
        '#default_value' => $config->get('spam_detect_action') === 'keep' ? 'keep' : 'discard',
      ],
    ];
    $has_webhook = (string) $this->secrets->get('slack_webhook', '') !== '';
    $form['slack'] = [
      '#type' => 'details',
      '#title' => $this->t('Slack'),
      '#open' => TRUE,
      'slack_webhook' => [
        '#type' => 'password',
        '#title' => $this->t('Slack incoming webhook URL'),
        '#description' => $has_webhook
          ? $this->t('A webhook is saved. Leave this empty to keep it.')
          : $this->t('Quant posts each submission to this Slack webhook. It is kept outside config, so a config export never contains it.'),
      ],
      'slack_remove' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove the saved webhook'),
        '#access' => $has_webhook,
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * One row for each Webform: tick it to take its submissions through Quant.
   */
  protected function buildFormsTable(): array {
    $table = [
      '#type' => 'tableselect',
      '#header' => [
        'form' => $this->t('Form'),
        'status' => $this->t('Static site'),
        'pages' => $this->t('Pages'),
      ],
      '#options' => [],
      '#default_value' => [],
      '#empty' => $this->t('There are no Webforms.'),
    ];
    foreach ($this->webformStorage->loadMultiple() as $id => $webform) {
      $pages = $this->registrar->pages($webform);
      $report = $this->mapper->map($webform, $pages[0] ?? '/');
      $status = match (TRUE) {
        !$report['supported'] => $this->t('Not supported: @why', ['@why' => implode(' ', array_map('strval', $report['blocking']))]),
        default => $report['notes'] === [] ? $this->t('Works') : $this->formatPlural(count($report['notes']), 'Works, with 1 difference:', 'Works, with @count differences:'),
      };
      $status_cell = (string) $status;
      if ($report['supported'] && $report['notes'] !== []) {
        $status_cell = [
          'data' => [
            '#theme' => 'item_list',
            '#prefix' => '<span>' . $status . '</span>',
            '#items' => array_map('strval', $report['notes']),
          ],
        ];
      }
      $table['#options'][$id] = [
        'form' => ['data' => ['#type' => 'link', '#title' => $webform->label(), '#url' => $webform->toUrl('settings')]],
        'status' => $status_cell,
        'pages' => $pages ? implode(', ', $pages) : (string) $this->t('No page'),
      ];
      $table['#default_value'][$id] = QuantFormsRegistry::isOptedIn($webform);
    }
    return $table;
  }

  /**
   * Opts the ticked forms in (and the others out), and registers the ticked.
   *
   * A form that changes is saved, which publishes it or turns it off. A form
   * that stays ticked is queued, so its settings are sent again.
   */
  public function submitRegisterAll(array &$form, FormStateInterface $form_state) {
    $ticked = array_filter((array) $form_state->getValue('forms'));
    $queued = 0;
    foreach ($this->webformStorage->loadMultiple() as $id => $webform) {
      $want = isset($ticked[$id]);
      if ($want !== QuantFormsRegistry::isOptedIn($webform)) {
        $webform->setThirdPartySetting('quant_webform', 'enabled', $want)->save();
        continue;
      }
      if ($want && $webform->isOpen()) {
        $queued += $this->registrar->queue($webform);
      }
    }
    $message = $this->formatPlural(count($ticked), 'Quant Forms is on for 1 form.', 'Quant Forms is on for @count forms.');
    if ($queued > 0) {
      $message .= ' ' . $this->formatPlural($queued, '1 page is queued to publish again on the next cron run (or drush quant:run-queue).', '@count pages are queued to publish again on the next cron run (or drush quant:run-queue).');
    }
    $this->messenger()->addStatus($message);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('quant_webform.settings')
      ->set('spam_threshold', (int) $form_state->getValue('spam_threshold'))
      ->set('spam_detect_action', $form_state->getValue('spam_detect_action') === 'keep' ? 'keep' : 'discard')
      ->save();
    if ($form_state->getValue('slack_remove')) {
      $this->secrets->delete('slack_webhook');
    }
    elseif ((string) $form_state->getValue('slack_webhook') !== '') {
      $this->secrets->set('slack_webhook', trim((string) $form_state->getValue('slack_webhook')));
    }
    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $webhook = trim((string) $form_state->getValue('slack_webhook'));
    if ($webhook !== '' && !preg_match('#^https://hooks\.slack\.com/#', $webhook)) {
      $form_state->setErrorByName('slack_webhook', $this->t('Enter a Slack incoming webhook URL. It starts with https://hooks.slack.com/.'));
    }
    parent::validateForm($form, $form_state);
  }

}
