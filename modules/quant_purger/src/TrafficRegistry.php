<?php

namespace Drupal\quant_purger;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\quant_purger\StackMiddleware\TraitUrlRegistrar;

/**
 * The quant traffic registry.
 */
class TrafficRegistry implements TrafficRegistryInterface {

  use TraitUrlRegistrar;

  /**
   * The active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The configuration object for quant purger.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a quant traffic registry event.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The active database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
    $this->config = \Drupal::configFactory()->get('quant_purger.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function add($url, array $tags) {
    $tags = ';' . implode(';', $tags) . ';';
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
    $this->connection->delete('purge_queuer_quant')->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getPaths(array $tags) {
    $urls = [];
    $tags = $this->getAcceptedCacheTags($tags);
    $or = new Condition('OR');
    foreach ($tags as $tag) {
      $condition = '%;' . $this->connection->escapeLike($tag) . ';%';
      $or->condition('tags', $condition, 'LIKE');
    }

    try {
      $results = $this->connection->select('purge_queuer_quant', 'q')
        ->fields('q', ['url'])
        ->condition($or)
        ->execute();
    }
    catch (\Exception $e) {
      // During install and uninstall the purge_queue_quant table may not
      // be available which can result in a race condition with this query,
      // return an empty URL list if the query fails.
      return $urls;
    }

    foreach ($results as $result) {
      $urls[] = $result->url;
    }

    return $urls;
  }

}
