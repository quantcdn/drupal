<?php

namespace Drupal\quant;

use Drupal\Core\Queue\QueueDatabaseFactory;
use Drupal\Core\Site\Settings;

/**
 * {@inheritdoc}
 */
class QuantQueueFactory extends QueueDatabaseFactory {

  /**
   * Get an instance of the factory.
   *
   * @return \Drupal\Core\Queue\QueueDatabaseFactory|QuantQueueFactory
   *   The queue factory based on site configuration.
   */
  public static function getInstance() {
    if (Settings::get('queue_service_quant_seed_worker') == "quant.queue_factory") {
      return \Drupal::service('quant.queue_factory');
    }
    else {
      return \Drupal::service('queue');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function get($name) {
    return new QuantQueue($name, $this->connection);
  }

}
