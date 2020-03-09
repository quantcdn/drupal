<?php

namespace Drupal\quant_api\Client;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\Client;


class QuantClient implements QuantClientInterface {

  /**
   * Build the QuantClient instance.
   */
  public function __construct(Client $client, ConfigFactoryInterface $config_factory) {
    $config = $config_factory->get('quant_api.settings');
    $this->client = $client;
    // @TODO: Grab API connection details from the config.
  }

  /**
   * {@inheritdoc}
   */
  public function ping() : bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function send(array $data) : bool {
    return TRUE;
  }

}
