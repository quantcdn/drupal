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

    $form['content_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable content revisions'),
      '#description' => $this->t('Any content change will create a new revision in Quant.'),
      '#default_value' => $config->get('content_revisions'),
    ];

    // @todo: Should revisions be configured at the API level?
    $form['asset_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable asset revisions'),
      '#description' => $this->t('Media revisions will be tracked when files/images/etc change.'),
      '#default_value' => $config->get('asset_revisions'),
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
      ->set('content_revisions', $form_state->getValue('content_revisions'))
      ->set('asset_revisions', $form_state->getValue('asset_revisions'))
      ->set('local_server', $form_state->getValue('local_server'))
      ->set('host_domain', $form_state->getValue('host_domain'))
      ->save();

    parent::submitForm($form, $form_state);
  }


}
