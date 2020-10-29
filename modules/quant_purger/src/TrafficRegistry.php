<?php

namespace Drupal\quant_purger;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;

/**
 * The quant traffic registry.
 */
class TrafficRegistry implements TrafficRegistryInterface {

  /**
   * The active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Constructs a quant traffic registry event.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The active database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public function add($url, array $tags) {
    $tags = ';' . implode(';', $tags);
    $fields = ['url' => $url, 'tags' => $tags];

    $this->connection->merge('purge_queuer_quant')
      ->insertFields($fields)
      ->updateFields($fields)
      ->key(['url' => $url])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function remove($url) {
    $this->connection->delete('purge_queuer_quant')
      ->condition('url', $url)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    $this->connection->delete('purge_queuer_quant');
  }

  /**
   * {@inheritdoc}
   */
  public function getPaths(array $tags) {
    $urls = [];

    $or = new Condition('OR');
    foreach ($tags as $tag) {
      $condition = '%;' . $this->connection->escapeLike($tag) . ';%';
      $or->condition('tags', $condition, 'LIKE');
    }

    $results = $this->connection->select('purge_queuer_quant', 'q')
      ->fields('q', ['url'])
      ->condition($or)
      ->execute();

    foreach ($results as $result) {
      $urls[] = $result->url;
    }

    return $urls;
  }

}
