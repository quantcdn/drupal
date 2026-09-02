<?php

namespace Drupal\quant_purger;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Condition;
use Drupal\quant_purger\StackMiddleware\TraitUrlRegistrar;

/**
 * The Quant traffic registry.
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
   * The configuration object for Quant purger.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a Quant traffic registry event.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The active database connection.
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
    $this->config = \Drupal::configFactory()->get('quant_purger.settings');
  }

  /**
   * Returns the domain currently being served.
   *
   * A site serving several domains from one Drupal instance has the same
   * path on every domain, so the registry has to keep them apart. Sites
   * without the Domain module record an empty string and behave as before.
   *
   * @return string
   *   The active domain id, or an empty string.
   */
  protected function getActiveDomainId() : string {
    if (!\Drupal::moduleHandler()->moduleExists('domain')) {
      return '';
    }

    if (!\Drupal::hasService('domain.negotiator')) {
      return '';
    }

    $domain = \Drupal::service('domain.negotiator')->getActiveDomain();

    return $domain ? $domain->id() : '';
  }

  /**
   * {@inheritdoc}
   */
  public function add($url, array $tags) {
    $tags = ';' . implode(';', $tags) . ';';
    $domain = $this->getActiveDomainId();
    $fields = ['url' => $url, 'domain' => $domain, 'tags' => $tags];

    // keys(), not key(): the latter takes a single field name and asserts on
    // an array, so the previous call broke under Drupal 10 and later.
    $this->connection->merge('purge_queuer_quant')
      ->insertFields($fields)
      ->updateFields($fields)
      ->keys(['url' => $url, 'domain' => $domain])
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function remove($url) {
    $this->connection->delete('purge_queuer_quant')
      ->condition('url', $url)
      ->condition('domain', $this->getActiveDomainId())
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function clear() {
    $delete = $this->connection->delete('purge_queuer_quant');

    // Scoped to the active domain, to match add() and remove(). An
    // administrator working on one client's domain would otherwise wipe the
    // registry for every other client, and each would silently stop purging
    // until its content was re-seeded. On a single-domain site the active
    // domain is the empty string, which is every row, so nothing changes.
    $delete->condition('domain', $this->getActiveDomainId());

    $delete->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function getPaths(array $tags) {
    $urls = [];

    foreach ($this->getPathsByDomain($tags) as $paths) {
      foreach ($paths as $path) {
        $urls[$path] = $path;
      }
    }

    return array_values($urls);
  }

  /**
   * {@inheritdoc}
   */
  public function getPathsByDomain(array $tags) {
    $paths = [];
    $tags = $this->getAcceptedCacheTags($tags);

    if (empty($tags)) {
      return $paths;
    }

    $or = new Condition('OR');
    foreach ($tags as $tag) {
      $condition = '%;' . $this->connection->escapeLike($tag) . ';%';
      $or->condition('tags', $condition, 'LIKE');
    }

    try {
      $results = $this->connection->select('purge_queuer_quant', 'q')
        ->fields('q', ['url', 'domain'])
        ->condition($or)
        ->execute();
    }
    catch (\Exception $e) {
      // During install and uninstall the purge_queuer_quant table may not
      // be available which can result in a race condition with this query,
      // return an empty list if the query fails.
      return $paths;
    }

    // The same page exists on every domain that serves it, and each domain
    // publishes to a different project, so the caller needs to know which
    // domain each path came from.
    foreach ($results as $result) {
      $paths[$result->domain][] = $result->url;
    }

    return $paths;
  }

}
