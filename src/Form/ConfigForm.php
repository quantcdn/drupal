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

    $form['storage_location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage path'),
      '#description' => $this->t('Location on disk to store static assets'),
      '#default_value' => $config->get('storage_location'),
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
      ->set('storage_location', $form_state->getValue('storage_location'))
      ->save();

    parent::submitForm($form, $form_state);
  }


}
