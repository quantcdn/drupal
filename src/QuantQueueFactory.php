<?php

namespace Drupal\quant;
use Drupal\Core\Queue\QueueDatabaseFactory;

class QuantQueueFactory extends QueueDatabaseFactory {
    /**
     * {@inheritdoc}
     */
    public function get($name) {
        return new QuantQueue($name, $this->connection);
    }
}