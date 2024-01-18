<?php

namespace Drupal\quant;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Site\Settings;

/**
 * Additional handling for Quant queue items.
 *
 * @see https://git.drupalcode.org/project/queue_unique
 */
class QuantQueue extends DatabaseQueue {
  /**
   * The table name to store the queue items.
   */
  public const TABLE_NAME = 'quant_queue';

  /**
   * Length of the hash coulmn.
   */
  public const HASH_LENGTH = 48;

  /**
   * The algorithm used to hash the item.
   */
  public const HASH_METHOD = 'sha512';

  /**
   * {@inheritdoc}
   */
  public function doCreateItem($data) {
    try {
      $serialized_data = serialize($data);
      $query = $this->connection->insert(static::TABLE_NAME)
        ->fields([
          'name' => $this->name,
          'data' => $serialized_data,
          'created' => time(),
          // Generate a near-unique value for this data on this queue.
          'hash' => static::hash($this->name, $serialized_data),
        ]);
      return $query->execute();
    }
    catch (IntegrityConstraintViolationException $err) {
      return FALSE;
    }
  }

  /**
   * Generate a hashed string from a queue name and serialized data.
   *
   * @param string $name
   *   The queue name.
   * @param string $serialized_data
   *   The serialized data.
   *
   * @return string
   *   The hash string.
   */
  public static function hash($name, $serialized_data) {
    $substr_length = static::HASH_LENGTH - strlen(static::HASH_METHOD);
    return static::HASH_METHOD . substr(base64_encode(hash('sha512', $name . $serialized_data, TRUE)), 0, $substr_length);
  }

  /**
   * Get the queue table name.
   *
   * @return string
   *   The table name to be used by this queue.
   */
  public static function getTableName() {
    if (Settings::get('queue_service_quant_seed_worker') == "quant.queue_factory") {
      return self::TABLE_NAME;
    }
    return 'queue';
  }

  /**
   * {@inheritdoc}
   */
  public function schemaDefinition() {
    return array_merge_recursive(
      parent::schemaDefinition(),
      // We cannot create a unique key on the data field because it is a blob.
      // Instead, we merge an additional field which should contain a hash
      // of the data and a unique key for this field into the original schema
      // definition. These are used to ensure uniqueness.
      [
        'fields' => [
          'hash' => [
            'type' => 'char',
            'length' => static::HASH_LENGTH,
            'not null' => TRUE,
          ],
        ],
        'unique keys' => [
          'unique' => ['hash'],
        ],
      ]
    );
  }

}
