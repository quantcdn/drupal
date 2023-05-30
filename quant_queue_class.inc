<?php

/**
 * @file
 * QuantQueue handler.
 */

class QuantQueue implements DrupalReliableQueueInterface {

  /**
   * The name of the queue this instance is working with.
   * 
   * @var string
   */
  protected $name;

  /**
   * Create an instance of QuantQueue.
   */
  public function __construct($name) {
    $this->name = $name;
  }

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {
    $db_data = serialize($data);
    $query = db_insert('quant_queue')
    ->fields(array(
      'name' => $this->name,
      'data' => $db_data,
      'created' => time(),
      'hash' => static::hash($this->name, $db_data),
    ));

    try {
      return (bool) $query->execute();
    } catch (\PDOException $error) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function numberOfItems() {
    return db_query('SELECT COUNT(item_id) FROM {quant_queue} WHERE name = :name', array(
      ':name' => $this->name,
    ))->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function claimItem($lease_time = 3600) {
    // Claim an item by updating its expire fields. If claim is not successful
    // another thread may have claimed the item in the meantime. Therefore loop
    // until an item is successfully claimed or we are reasonably sure there
    // are no unclaimed items left.
    while (TRUE) {
      $item = db_query_range('SELECT data, item_id FROM {quant_queue} q WHERE expire = 0 AND name = :name ORDER BY created, item_id ASC', 0, 1, array(
        ':name' => $this->name,
      ))->fetchObject();

      if ($item) {
        $update = db_update('quant_queue')
          ->fields(array(
              'expire' => time() + $lease_time,
            ))
          ->condition('item_id', $item->item_id)
          ->condition('expire', 0);

        // If there are affected rows, this update succeeded.
        if ($update->execute()) {
          $item->data = unserialize($item->data);
          return $item;
        }
      }
      else {
        // No items currently available to claim.
        return FALSE;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function releaseItem($item) {
    $update = db_update('quant_queue')
      ->fields(array(
        'expire' => 0,
      ))
      ->condition('item_id', $item->item_id);
    
    return $update->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItem($item) {
    db_delete('quant_queue')
      ->condition('item_id', $item->item_id)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function createQueue() {
    // QuantQueue creates a table during install, we don't 
    // need to perform any other tasks during queue creation.
  }

  /**
   * {@inheritdoc}
   */
  public function deleteQueue() {
    db_delete('quant_queue')
      ->condition('name', $this->name)
      ->execute();
  }

  /**
   * Generate a hashed string from the queue name and data.
   * 
   * @param string $name
   *   The queue name.
   * @param mixed $data
   *   The queue item data.
   * 
   * @return string
   *   The hash string.
   */
  public static function hash($name, $data) {
    $method = variable_get('quant_queue_hash_method', 'sha256');
    $length = variable_get('quant_queue_hash_length', 48);

    if (!is_string($data)) {
      $data = serialize($data);
    }

    $substr_length = $length - strlen($method);
    return $method . substr(base64_encode(hash($method, $name . $data, TRUE)), 0, $substr_length);
  }

}
