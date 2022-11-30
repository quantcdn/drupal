<?php

namespace Drupal\quant_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class SettingsForm extends ConfigFormBase {

  const SETTINGS = 'quant_api.settings';

  /**
   * Build the form.
   */
  public function __construct(QuantClientInterface $client) {
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quant_api.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames() {
    return [
      self::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::SETTINGS);

    if ($config->get('api_token')) {
      if ($project = $this->client->ping()) {
        $message = t('Successfully connected to @api', ['@api' => $config->get('api_project')]);
        \Drupal::messenger()->addMessage($message);
      }
      else {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
      }
    }

    $form['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Endpoint'),
      '#description' => $this->t('e.g: https://api.quantcdn.io'),
      '#default_value' => $config->get('api_endpoint'),
      '#required' => TRUE,
    ];

    $form['api_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Account'),
      '#default_value' => $config->get('api_account'),
      '#required' => TRUE,
    ];

    $form['api_project'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Project'),
      '#default_value' => $config->get('api_project'),
      '#required' => TRUE,
    ];

    $form['api_token'] = [
      '#type' => 'password',
      '#title' => $this->t('API Token'),
      '#default_value' => $config->get('api_token'),
      '#required' => TRUE,
    ];

    $form['api_tls_disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable TLS verification'),
      '#description' => $this->t('Old webservers may have issues validating modern certificates. Only disable if absolutely necessary.'),
      '#default_value' => $config->get('api_tls_disabled', FALSE),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Retrieve the configuration.
    $this->configFactory->getEditable(self::SETTINGS)
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->set('api_token', $form_state->getValue('api_token'))
      ->set('api_project', $form_state->getValue('api_project'))
      ->set('api_account', $form_state->getValue('api_account'))
      ->set('api_tls_disabled', $form_state->getValue('api_tls_disabled'))
      ->save();

    // Clear router cache in case search has been enabled or disabled.
    // @todo Need to clear any search autocomplete blocks in case of config change.
    \Drupal::service('router.builder')->rebuild();

    parent::submitForm($form, $form_state);
  }

}
