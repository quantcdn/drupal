<?php

namespace Drupal\quant_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\quant_api\Client\QuantClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Quant configuration form.
 *
 * @see Drupal\Core\Form\ConfigFormBase
 */
class Search extends ControllerBase {

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
  public function statusPage() {
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

    // Retrieve search stats.
    $search = $this->client->search();

    if (!isset($search->index)) {
      return [
        '#markup' => $this->t('Unable to retrieve search index values.')
      ];
    }

    return [
      '#theme' => 'search_page_status',
      '#index' => $search->index,
      '#settings' => $search->settings,
      '#pages' => NULL,
    ];
  }


  /**
   * {@inheritdoc}
   */
  public function searchPage($page) {

    $project = $this->client->project();

    return [
      '#theme' => 'search_page',
      '#attached' => [
        'library' => [
          'quant_search/algolia',
        ],
        'drupalSettings' => [
          'quantSearch' => [
            'algolia_application_id' => $project->config->search_index->algolia_application_id,
            'algolia_read_key' => $project->config->search_index->algolia_read_key,
            'algolia_index' => $project->config->search_index->algolia_index,
          ]
        ]
      ],
      '#index' => $project->config->search_index,
      '#page' => $page->toArray(),
    ];

  }

}
