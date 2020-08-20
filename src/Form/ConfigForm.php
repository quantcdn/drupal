<?php

namespace Drupal\quant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Contains a form for configuring Quant.
 *
 * @internal
 */
class ConfigForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'quant.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_config_form';
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

    $form['disable_content_drafts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable content drafts'),
      '#description' => $this->t('Prevent draft content from being sent to Quant.'),
      '#default_value' => $config->get('disable_content_drafts'),
    ];

    $form['proxy_override'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override existing proxies'),
      '#description' => $this->t('Overrides proxies created via the dashboard.'),
      '#default_value' => $config->get('proxy_override'),
    ];

    $form['local_server'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webserver URL'),
      '#description' => $this->t('Provide the FQDN that internal requests may route to. e.g: <em>http://localhost</em>, <em>http://nginx:8080</em> or <em>http://127.0.0.1</em>. <a href="https://support.quantcdn.io/setup/drupal">More info.</a>'),
      '#default_value' => $config->get('local_server', 'http://localhost'),
    ];

    $form['host_domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hostname'),
      '#description' => $this->t('Optionally provide the expected hostname for content served via Quant. This ensures absolute links in content point to the correct domain. e.g: <em>www.example.com</em> <a href="https://support.quantcdn.io/setup/drupal">More info.</a>'),
      '#default_value' => $config->get('host_domain'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('proxy_override', $form_state->getValue('proxy_override'))
      ->set('local_server', $form_state->getValue('local_server'))
      ->set('host_domain', $form_state->getValue('host_domain'))
      ->set('disable_content_drafts', $form_state->getValue('disable_content_drafts'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
