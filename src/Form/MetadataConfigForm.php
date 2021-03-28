<?php

namespace Drupal\quant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * The metadata configuration form.
 */
class MetadataConfigForm extends ConfigFormBase {

  const SETTINGS = 'quant.metadata.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_metadata_config_form';
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

    $meta = \Drupal::service('plugin.manager.quant.metadata');

    $form['plugins'] = [
      '#type' => 'vertical_tabs',
    ];

    foreach ($meta->getDefinitions() as $pid => $def) {
      $plugin = $meta->createInstance($pid);
      $plugin_form = $plugin->buildConfigurationForm();

      if (empty($plugin_form)) {
        continue;
      }

      $form[$pid] = [
        '#type' => 'details',
        '#title' => $def['label'],
        '#description' => $def['description'],
        '#group' => 'plugins',
        '#tree' => TRUE,
      ];

      $form[$pid] = array_merge($form[$pid], $plugin_form);
    }

    $form['test'] = [
      '#type' => 'text',
      '#title' => 'test',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // @todo Submits on the plugins?...
    $config = $this->configFactory->getEditable(static::SETTINGS);
    $values = $form_state->getValues();
    $meta = \Drupal::service('plugin.manager.quant.metadata');
    foreach ($meta->getDefinitions() as $pid => $def) {
      if (isset($values[$pid])) {
        $config->set($pid, $values[$pid]);
      }
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
