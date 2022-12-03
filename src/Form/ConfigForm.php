<?php

namespace Drupal\quant\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant\Seed;

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

    $tokenConfig = $this->config('quant.token_settings');

    if ($tokenConfig->get('disable')) {
      \Drupal::messenger()->addWarning(t('Internal Quant tokens are disabled. It is recommended these are enabled where possible.'));
    }

    $this->checkValidationRoute();

    $form['quant_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Track content change'),
      '#description' => $this->t('Automatically push content changes to Quant (recommended).'),
      '#default_value' => $config->get('quant_enabled', TRUE),
    ];

    $form['tracking_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracked entities'),
      '#states' => [
        'visible' => [
          ':input[name="quant_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['tracking_fieldset']['quant_enabled_nodes'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Nodes'),
      '#default_value' => $config->get('quant_enabled_nodes'),
    ];

    $form['tracking_fieldset']['quant_enabled_taxonomy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Taxonomy Terms'),
      '#default_value' => $config->get('quant_enabled_taxonomy'),
    ];

    $form['tracking_fieldset']['quant_enabled_redirects'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redirects'),
      '#default_value' => $config->get('quant_enabled_redirects'),
    ];

    $form['tracking_fieldset']['quant_enabled_views'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Views'),
      '#default_value' => $config->get('quant_enabled_views'),
    ];

    $form['follow_links_fieldset'] = [
      '#type' => 'details',
      '#title' => $this->t('Follow links'),
      '#description' => $this->t('Automatically add certain links to the queue (e.g Views pagination)'),
    ];

    $form['follow_links_fieldset']['xpath_selectors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Links to follow'),
      '#default_value' => $config->get('xpath_selectors'),
      '#description' => $this->t('Provide one xpath per line for anchor links to queue when detected.'),
    ];

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
      '#description' => $this->t('Provide the FQDN for the local webserver. e.g: <em>http://localhost</em>, <em>http://nginx:8080</em> or <em>http://127.0.0.1</em>. <a href="https://docs.quantcdn.io/docs/integrations/drupal#setup">More info.</a>'),
      '#default_value' => $config->get('local_server') ?: 'http://localhost',
      '#required' => TRUE,
    ];

    $form['host_domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('HTTP Host header'),
      '#description' => $this->t('Optionally provide the expected host header for HTTP requests to the local webserver. This ensures absolute links in content point to the correct domain. e.g: <em>www.example.com</em> <a href="https://docs.quantcdn.io/docs/integrations/drupal#setup">More info.</a>'),
      '#default_value' => $config->get('host_domain'),
    ];

    $form['host_domain_strip'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Strip host domain from content'),
      '#description' => $this->t('Optionally strip out the host domain above from any generated content. This includes body content and header metadata such as canonical links. If disabled, check your content is using relative links as expected.'),
      '#default_value' => $config->get('host_domain_strip'),
    ];

    $form['ssl_cert_verify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verify SSL certificates'),
      '#description' => $this->t('Verify TLS on local webserver. Disable if using self-signed certificates.'),
      '#default_value' => $config->get('ssl_cert_verify'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Retrieve the configuration.
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('quant_enabled', $form_state->getValue('quant_enabled'))
      ->set('quant_enabled_nodes', $form_state->getValue('quant_enabled_nodes'))
      ->set('quant_enabled_taxonomy', $form_state->getValue('quant_enabled_taxonomy'))
      ->set('quant_enabled_views', $form_state->getValue('quant_enabled_views'))
      ->set('quant_enabled_redirects', $form_state->getValue('quant_enabled_redirects'))
      ->set('proxy_override', $form_state->getValue('proxy_override'))
      ->set('local_server', $form_state->getValue('local_server'))
      ->set('host_domain', $form_state->getValue('host_domain'))
      ->set('host_domain_strip', $form_state->getValue('host_domain_strip'))
      ->set('disable_content_drafts', $form_state->getValue('disable_content_drafts'))
      ->set('ssl_cert_verify', $form_state->getValue('ssl_cert_verify'))
      ->set('xpath_selectors', $form_state->getValue('xpath_selectors'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (filter_var($form_state->getValue('local_server'), FILTER_VALIDATE_URL) === FALSE) {
      $form_state->setErrorByName('local_sever', $this->t('Invalid local server URL.'));
    }
  }

  /**
   * Checks the Quant validation route for an expected response.
   *
   * @return bool
   *   If quant can connect to local webserver or not.
   */
  private function checkValidationRoute() {

    $base = \Drupal::request()->getBaseUrl();
    $markup = Seed::markupFromRoute($base . '/quant/validate');

    if (!empty($markup[0])) {
      if (strpos($markup[0], 'quant success') !== FALSE) {
        \Drupal::messenger()->addMessage(t('Connected successfully.'));
        return TRUE;
      }
    }

    \Drupal::messenger()->addError(t('Unable to connect to local webserver. Check webserver and host header settings.'));
    return FALSE;

  }

}
