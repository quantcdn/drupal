<?php

namespace Drupal\quant_purger\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\purge_ui\Form\QueuerConfigFormBase;

/**
 * Configuration form for the Quant queuer.
 */
class ConfigurationForm extends QueuerConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['quant_purger.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_purger.configuration_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('quant_purger.settings');
    $settings = ['path_blocklist', 'tag_blocklist'];

    foreach ($settings as $key) {
      $form["{$key}_fieldset"] = [
        '#type' => 'fieldset',
        '#title' => ucfirst(str_replace('_', ' ', $key)),
      ];

      $items = $config->get($key);
      if (!is_array($items)) {
        $items = explode(PHP_EOL, $items);
      }

      if (is_null($form_state->get("{$key}_count"))) {
        $form_state->set("{$key}_count", count($items) ?: 1);
      }

      $max = $form_state->get("{$key}_count");

      $form["{$key}_fieldset"][$key] = [
        '#prefix' => '<div id="' . str_replace('_', '-', $key) . '-wrapper">',
        '#suffix' => '</div>',
        "#tree" => TRUE,
      ];

      for ($delta = 0; $delta < $max; $delta++) {
        if (empty($form["{$key}_fieldset"][$key][$delta])) {
          $form["{$key}_fieldset"][$key][$delta] = [
            '#type' => 'textfield',
            '#default_value' => $items[$delta] ?? '',
          ];
        }
      }

      $form["{$key}_fieldset"]['add'] = [
        '#type' => 'submit',
        '#name' => "{$key}_add",
        '#value' => $this->t('Add %key', [
          '%key' => str_replace('_', ' ', $key),
        ]),
        '#submit' => [[$this, 'addMoreSubmit']],
        '#ajax' => [
          'callback' => [$this, 'addMoreCallback'],
          'wrapper' => str_replace('_', '-', $key) . '-wrapper',
          'effect' => 'fade',
        ],
      ];
    }

    $form['path_blocklist_fieldset']['#description'] = $this->t('The Quant purge querer collects HTTP requests that the Quant module makes to generate static representations of content. It requires that the request has a valid token to limit the performance impact of gathering traffic information in such a manner. This is a user managed list of paths and query strings that will be excluded from traffic gathering.');

    $form['tag_blocklist_fieldset']['#description'] = $this->t('By default all cache tag invalidations will trigger a queue entry. Some of these invalidations can have widespread effects on the site and require a full content seed. This allows you to exclude certain tags from triggering a content re-index.');

    $form['actions']['clear'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear the registry'),
      '#weight' => 10,
      '#button_type' => 'danger',
      '#ajax' => [
        'callback' => '::submitFormClear',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Let the form rebuild the blacklist textfields.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function addMoreSubmit(array &$form, FormStateInterface $form_state) {
    $key = str_replace('_add', '', $form_state->getTriggeringElement()['#name']);
    $count = $form_state->get("{$key}_count");
    $count++;
    $form_state->set("{$key}_count", $count);
    $form_state->setRebuild();
  }

  /**
   * Adds more textfields to the blacklist fieldset.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function addMoreCallback(array &$form, FormStateInterface $form_state) {
    $key = str_replace('_add', '', $form_state->getTriggeringElement()['#name']);
    return $form["{$key}_fieldset"][$key];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('quant_purger.settings')
      ->set('tag_blocklist', $form_state->getValue('tag_blocklist'))
      ->set('path_blocklist', $form_state->getValue('path_blocklist'))
      ->save();

    return parent::submitForm($form, $form_state);
  }

  /**
   * Clear the registry.
   *
   * @param array $form
   *   The form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   */
  public function submitFormClear(array &$form, FormStateInterface $form_state) {
    if (!$form_state->getErrors()) {
      \Drupal::service('quant_purger.registry')->clear();
    }
  }

}
