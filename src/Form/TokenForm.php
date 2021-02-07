<?php

namespace Drupal\quant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration for token handling.
 */
class TokenForm extends ConfigFormBase {

  const SETTINGS = 'quant.token_settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_token_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    $form['timeout'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Token timeout'),
      '#description' => $this->t('Duration of a valid token relative to now. Must be compatible with <a href="https://www.php.net/manual/en/function.strtotime.php" target="_blank">strtotime</a>'),
      '#default_value' => !empty($config->get('timeout')) ? $config->get('timeout') : '+1 minute',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $timestamp = strtotime($form_state->getValue('timeout'));

    if (FALSE === $timestamp) {
      $form_state->setErrorByName('timeout', $this->t('Invalid pattern for token timeout.'));
    }

    if ($timestamp < strtotime('now')) {
      $form_state->setErrorByName('timeout', $this->t('Only future timeouts are supported.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('timeout', $form_state->getValue('timeout'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
