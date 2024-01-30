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
   * The Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface
   */
  protected $client;

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
        $message = $this->t('QuantAPI status: Successfully connected to project <code>@project</code>', ['@project' => $config->get('api_project')]);
        \Drupal::messenger()->addMessage($message);
      }
      else {
        \Drupal::messenger()->addError($this->t('QuantAPI error: Unable to connect to the Quant API please check the endpoint on the <code>Integrations</code> page in the Quant dashboard.'));
      }
    }

    $form['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Endpoint'),
      '#description' => $this->t('The fully-qualified domain name for the API endpoint, e.g. <code>https://api.quantcdn.io</code>, shown on the <code>Integrations</code> page. Update via drush or settings.php if necessary.'),
      '#default_value' => $config->get('api_endpoint', 'https://api.quantcdn.io'),
      '#required' => TRUE,
      '#disabled' => TRUE,
    ];

    // @todo Switch from 'api_account' to 'api_organization'.
    $form['api_account'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Organization'),
      '#description' => $this->t('The API organization. This is shown on the <code>Integrations</code> page and with the account information.'),
      '#default_value' => $config->get('api_account'),
      '#required' => TRUE,
    ];

    $form['api_project'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Project'),
      '#description' => $this->t('The API project. This is the <code>"API name"</code> shown on the <code>Projects</code> page and the <code>"Project"</code> shown on the <code>Integrations</code> page. Note, this value may be different than the human-readable project name.'),
      '#default_value' => $config->get('api_project'),
      '#required' => TRUE,
    ];

    $form['api_token'] = [
      '#type' => 'password',
      '#title' => $this->t('API Token'),
      '#description' => $this->t('The API token. Use the clipboard icon in the dashboard to copy the token from the <code>Projects</code> or <code>Integrations</code> page. Be careful with this information. It should be treated like any system password.'),
      '#default_value' => $config->get('api_token'),
      '#required' => TRUE,
    ];

    $form['api_tls_disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable TLS verification'),
      '#description' => $this->t('You can optionally disable TLS verification for all Quant API requests. This is <strong>not recommended</strong>, but may be necessary in some configurations. For example, old web servers may have issues validating modern TSL/SSL certificates.'),
      '#default_value' => $config->get('api_tls_disabled', FALSE),
    ];

    // API values might be overridden in the settings file.
    $overrides = $this->client->getOverrides();
    foreach ($overrides as $key => $value) {
      if ($key === 'api_token') {
        // Don't show the token in the UI.
        $message = $this->t('QuantAPI override: <code>api_token</code> has been overridden in the settings file.');
      }
      else {
        $message = $this->t('QuantAPI override: <em>@key</em> has been overridden in the settings file with <em>@value</em>.',
          [
            '@key' => $key,
            '@value' => $value,
          ]);
      }

      // Show warning and add to description.
      \Drupal::messenger()->addWarning($message);
      $form[$key]['#description'] = $form[$key]['#description'] . ' <strong>' . $message . '</strong>';
    }

    // Show error if not using TSL verification.
    if ((isset($overrides['api_tls_disabled']) && $overrides['api_tls_disabled']) || $config->get('api_tls_disabled')) {
      \Drupal::messenger()->addError($this->t('<strong>DANGER ZONE:</strong> TLS verification is disabled for Quant API connections. It is <strong>highly recommended</strong> that you update your server configuration to handle TLS rather than disabling TLS verification.'));
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Retrieve the configuration.
    // @todo Switch from 'api_account' to 'api_organization'.
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
