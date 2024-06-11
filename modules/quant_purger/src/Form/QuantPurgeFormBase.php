<?php

namespace Drupal\quant_purger\Form;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface;
use Drupal\purge_ui\Form\PurgerConfigFormBase;
use Drupal\quant_purger\Entity\QuantPurgeSettings;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Abstract form base for HTTP based configurable purgers.
 */
abstract class QuantPurgeFormBase extends PurgerConfigFormBase {

  /**
   * The service that generates invalidation objects on-demand.
   *
   * @var \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface
   */
  protected $purgeInvalidationFactory;

  /**
   * Constructs a \Drupal\quant_purger\Form\ConfigurationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface $purge_invalidation_factory
   *   The invalidation objects factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entityTypeManager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, InvalidationsServiceInterface $purge_invalidation_factory, EntityTypeManagerInterface $entityTypeManager) {
    $this->setConfigFactory($config_factory);
    $this->purgeInvalidationFactory = $purge_invalidation_factory;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('purge.invalidation.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [];
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
    $settings = QuantPurgeSettings::load($this->getId($form_state));
    $form['tabs'] = ['#type' => 'vertical_tabs', '#weight' => 10];
    $this->buildFormMetadata($form, $form_state, $settings);
    $this->buildFormPerformance($form, $form_state, $settings);
    return parent::buildForm($form, $form_state);
  }

  /**
   * Build the 'metadata' quant of the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\quant_purger\Entity\QuantPurgeSettings $settings
   *   Configuration entity for the purger being configured.
   */
  public function buildFormMetadata(array &$form, FormStateInterface $form_state, QuantPurgeSettings $settings) {
    $form['name'] = [
      '#title' => $this->t('Name'),
      '#type' => 'textfield',
      '#description' => $this->t('Quant Purger for Drupal.'),
      '#default_value' => $settings->name,
      '#required' => TRUE,
    ];
    $types = [];
    foreach ($this->purgeInvalidationFactory->getPlugins() as $type => $definition) {
      $types[$type] = (string) $definition['label'];
    }
  }

  /**
   * Build the 'performance' section of the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param \Drupal\quant_purger\Entity\QuantPurgeSettings $settings
   *   Configuration entity for the purger being configured.
   */
  public function buildFormPerformance(array &$form, FormStateInterface $form_state, QuantPurgeSettings $settings) {
    $form['performance'] = [
      '#type' => 'details',
      '#group' => 'tabs',
      '#title' => $this->t('Performance'),
    ];
    $form['performance']['cooldown_time'] = [
      '#type' => 'number',
      '#step' => 0.1,
      '#min' => 0.0,
      '#max' => 3.0,
      '#title' => $this->t('Cooldown time'),
      '#default_value' => $settings->cooldown_time,
      '#required' => TRUE,
      '#description' => $this->t('Number of seconds to wait after a group of HTTP requests (so that other purgers get fresh content)'),
    ];
    $form['performance']['max_requests'] = [
      '#type' => 'number',
      '#step' => 1,
      '#min' => 1,
      '#max' => 500,
      '#title' => $this->t('Maximum requests'),
      '#default_value' => $settings->max_requests,
      '#required' => TRUE,
      '#description' => $this->t("Maximum number of HTTP requests that can be made during Drupal's execution lifetime. Usually PHP resource restraints lower this value dynamically, but can be met at the CLI."),
    ];
    $form['performance']['runtime_measurement'] = [
      '#title' => $this->t('Runtime measurement'),
      '#type' => 'checkbox',
      '#default_value' => $settings->runtime_measurement,
    ];
    $form['performance']['runtime_measurement_help'] = [
      '#type' => 'item',
      '#states' => [
        'visible' => [
          ':input[name="runtime_measurement"]' => ['checked' => FALSE],
        ],
      ],
      '#description' => $this->t('When you uncheck this setting, capacity will be based on the sum of both timeouts. By default, capacity will automatically adjust (up and down) based on measured time data.'),
    ];
    $form['performance']['timeout'] = [
      '#type' => 'number',
      '#step' => 0.1,
      '#min' => 0.1,
      '#max' => 8.0,
      '#title' => $this->t('Timeout'),
      '#default_value' => $settings->timeout,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="runtime_measurement"]' => ['checked' => FALSE],
        ],
      ],
      '#description' => $this->t('The timeout of the request in seconds.'),
    ];
    $form['performance']['connect_timeout'] = [
      '#type' => 'number',
      '#step' => 0.1,
      '#min' => 0.1,
      '#max' => 4.0,
      '#title' => $this->t('Connection timeout'),
      '#default_value' => $settings->connect_timeout,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="runtime_measurement"]' => ['checked' => FALSE],
        ],
      ],
      '#description' => $this->t('The number of seconds to wait while trying to connect to a server.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Validate that our timeouts stay between the boundaries purge demands.
    $timeout = $form_state->getValue('connect_timeout') + $form_state->getValue('timeout');
    if ($timeout > 10) {
      $form_state->setErrorByName('connect_timeout');
      $form_state->setErrorByName('timeout', $this->t('The sum of both timeouts cannot be higher than 10.00 as this would affect performance too negatively.'));
    }
    elseif ($timeout < 0.4) {
      $form_state->setErrorByName('connect_timeout');
      $form_state->setErrorByName('timeout', $this->t('The sum of both timeouts cannot be lower as 0.4 as this can lead to too many failures under real usage conditions.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitFormSuccess(array &$form, FormStateInterface $form_state) {
    $settings = QuantPurgeSettings::load($this->getId($form_state));

    // Iterate the config object and overwrite values found in the form state.
    foreach ($settings as $key => $default_value) {
      if (!is_null($value = $form_state->getValue($key))) {
        $settings->$key = $value;
      }
    }
    $settings->save();
  }

}
