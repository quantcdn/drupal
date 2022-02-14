<?php

namespace Drupal\quant\Queue;

use Drupal\Core\Queue\DatabaseQueue;

/**
 * The seed queue.
 */
class SeedQueue extends DatabaseQueue {

  /**
   * Determine if the queue has the item that we're trying to create.
   *
   * @param mixed $data
   *   The queue data.
   */
  public function hasItem($data) {
    $query = $this->connection->query("SELECT item_id from " . static::TABLE_NAME . " WHERE md5(data) = :md5", [
      ':md5' => md5(serialize($data)),
    ]);
    return count($query->fetchAll()) > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {
    if (!$this->hasItem($data)) {
      return parent::createItem($data);
    }
    else {
      return FALSE;
    }
  }

}
