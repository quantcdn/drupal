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

    $form['disable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable token verification'),
      '#description' => $this->t('Not recommended for production environments, this disables token verification'),
      '#default_value' => $config->get('disable'),
    ];

    $form['strict'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable strict tokens'),
      '#description' => $this->t('Allow token verification process to perform route validations. This may not work for all Drupal configurations'),
      '#default_value' => $config->get('strict'),
    ];

    $form['timeout'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Token timeout'),
      '#description' => $this->t('Duration of a valid token relative to now. Must be compatible with <a href="https://www.php.net/manual/en/function.strtotime.php" target="_blank">strtotime</a>'),
      '#default_value' => !empty($config->get('timeout')) ? $config->get('timeout') : '+1 minute',
      '#required' => TRUE,
    ];

    $form['generate_secret'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate a new secret token'),
      '#description' => $this->t('Regenerate the secret token used to sign internal requests'),
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
    $config = $this->config(static::SETTINGS);
    $editable = $this->configFactory->getEditable(static::SETTINGS);

    if ($form_state->getValue('generate_secret') || empty($config->get('secret'))) {
      $editable->set('secret', bin2hex(random_bytes(32)));
    }

    if ($form_state->getValue('disable')) {
      $form_state->setValue('strict', 0);
    }

    $editable
      ->set('timeout', $form_state->getValue('timeout'))
      ->set('disable', $form_state->getValue('disable'))
      ->set('strict', $form_state->getValue('strict'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
