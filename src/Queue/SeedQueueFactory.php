<?php

namespace Drupal\quant\Queue;

use Drupal\Core\Queue\QueueDatabaseFactory;

/**
 * Factory to return a unique queue item.
 */
class SeedQueueFactory extends QueueDatabaseFactory {

  /**
   * {@inheritdoc}
   */
  public function get($name) {
    return new SeedQueue($name, $this->connection);
  }

}
