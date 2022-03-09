<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class SearchEntitiesForm extends ConfigFormBase {

  const SETTINGS = 'quant_search.entities.settings';

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
    return 'quant_search.entities';
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
      if ($project = $this->client->project()) {
        if ($project->config->search_enabled) {
          $message = t('Search is enabled for @api', ['@api' => $config->get('api_project')]);
          \Drupal::messenger()->addMessage($message);
        }
        else {
          \Drupal::messenger()->addError(t('Search is not enabled for this project. Enable via the Quant Dashboard.'));
        }
      }
      else {
        \Drupal::messenger()->addError(t('Unable to connect to Quant API, check settings.'));
      }
    }

    $form['quant_search_records_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Push search records'),
      '#description' => $this->t('Provide search record data when content is pushed.'),
      '#default_value' => $config->get('quant_search_records_enabled', TRUE),
    ];

    // @todo: Proper per-entity configs, tokens, stuff
    $form['quant_search_records_title_token'] = [
      '#type' => 'text',
      '#title' => $this->t('Title token'),
      '#description' => $this->t('Provide search record data when content is pushed.'),
      '#default_value' => $config->get('quant_search_records_enabled', TRUE),
    ];

    $form['search_tokens'] = [
      '#type' => 'vertical_tabs',
    ];

    $form['search_tokens_node'] = [
      '#type' => 'details',
      '#title' => 'Node',
      '#description' => 'Tokens related to nodes',
      '#group' => 'search_tokens',
      '#tree' => TRUE,
    ];

    $form['search_tokens_node']['quant_search_title_token'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#description' => 'Title',
      '#default_value' => $config->get('quant_search_title_token'),
    ];

    $form['search_tokens_node']['quant_search_summary_token'] = [
      '#type' => 'textfield',
      '#title' => 'Summary',
      '#description' => 'Summary',
      '#default_value' => $config->get('quant_search_summary_token'),
    ];

    $form['search_tokens_node']['quant_search_content_viewmode'] = [
      '#type' => 'textfield',
      '#title' => 'Content view mode',
      '#description' => 'View mode to render the content as for search body',
      '#default_value' => $config->get('quant_search_content_viewmode'),
    ];



    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $nodeTokens = $form_state->getValue('search_tokens_node');

    // Retrieve the configuration.
    $this->configFactory->getEditable(self::SETTINGS)
      ->set('quant_search_records_enabled', $form_state->getValue('quant_search_records_enabled'))
      ->set('quant_search_title_token', $nodeTokens['quant_search_title_token'])
      ->set('quant_search_summary_token', $nodeTokens['quant_search_summary_token'])
      ->set('quant_search_content_viewmode', $nodeTokens['quant_search_content_viewmode'])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
