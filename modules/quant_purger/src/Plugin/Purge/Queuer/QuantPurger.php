<?php

namespace Drupal\quant_purger\Plugin\Purge\Queuer;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Psr\Container\ContainerInterface;

/**
 * Queues URLs with Quant when Drupal invalidates cache tags.
 */
class QuantPurger implements CacheTagsInvalidatorInterface {

  /**
   * A list of tags that have already been invalidated in this request.
   *
   * @var string[]
   */
  protected $invalidatedTags = [];

  /**
   * Purge's invalidation object factory.
   *
   * @var null|\Drupal\purge\Plugin\Purge\Invalidation\InvalidationsServiceInterface
   */
  protected $purgeInvalidationFactory;

  /**
   * Purge's queue service.
   *
   * @var null|\Drupal\purge\Plugin\Purge\Queue\QueueServiceInterface
   */
  protected $purgeQueue;

  /**
   * The traffic registry with the stored URLs and tags.
   *
   * @var null|\Drupal\quant_purger\TrafficRegistryInterface
   */
  protected $registry;

  /**
   * The queuer plugin or FALSE when the plugin is disabled.
   *
   * @var null|false|\Drupal\quant_purger\Plugin\Purge\Queuer\QuantPurgerPlugin
   */
  protected $queuer;

  /**
   * Quant's queue service.
   *
   * @var null|\Drupal\Core\Queue\QueueInterface
   */
  protected $quantSeedQueue;

  /**
   * The service container.
   */
  protected ContainerInterface $container;

  /**
   * Sets the service container.
   */
  public function setContainer(ContainerInterface $container): void {
    $this->container = $container;
  }

  /**
   * Initialize the invalidation factory and queue service.
   *
   * @return bool
   *   TRUE when everything is available, FALSE when our plugin is disabled.
   */
  protected function initialize() {
    if (is_null($this->queuer)) {
      $this->queuer = $this->container->get('purge.queuers')->get('quant');
      if ($this->queuer !== FALSE) {
        $factory = $this->container->get('queue');
        $this->purgeInvalidationFactory = $this->container->get('purge.invalidation.factory');
        $this->purgeQueue = $this->container->get('purge.queue');
        $this->registry = $this->container->get('quant_purger.registry');
        $this->quantSeedQueue = $factory->get('quant_seed_worker');
      }
    }
    return $this->queuer !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    if (!$this->initialize()) {
      return;
    }

    // Clean up the tags that we care about for this run.
    $tags = array_diff($tags, $this->invalidatedTags);

    if (empty($tags)) {
      // We've purged all the tags during runtime.
      return;
    }

    $pathsByDomain = $this->registry->getPathsByDomain($tags);

    foreach ($tags as $tag) {
      $this->invalidatedTags[] = $tag;
    }

    // A page shown on several domains has to be purged on each of them, and
    // each domain publishes to its own project. Stamp every item with the
    // project that owns it rather than the one this request happens to be
    // serving, otherwise only the current domain is refreshed.
    foreach ($pathsByDomain as $domainId => $paths) {
      $project = $this->getProjectForDomain($domainId);

      foreach ($paths as $path) {
        $this->quantSeedQueue->createItem(new RouteItem([
          'route' => $path,
          'target_project' => $project,
        ]));
      }
    }
  }

  /**
   * Resolves the Quant project a given domain publishes to.
   *
   * @param string $domainId
   *   The domain id, or an empty string on a single-domain site.
   *
   * @return string|null
   *   The project machine name, or NULL when none is configured.
   */
  protected function getProjectForDomain($domainId) {
    $container = $this->container ?: \Drupal::getContainer();
    $configFactory = $container->get('config.factory');

    if (!empty($domainId)) {
      foreach ($this->readDomainOverride($container, $domainId) as $project) {
        if (!empty($project)) {
          return $project;
        }
      }

      // A domain with no override of its own publishes to the base project.
      // Read it without overrides: in this process the config factory is
      // already resolving them for whichever domain is being served.
      $base = $configFactory->get('quant_api.settings')->getOriginal('api_project', FALSE);

      return $base ?: NULL;
    }

    return $configFactory->get('quant_api.settings')->get('api_project') ?: NULL;
  }

  /**
   * Reads a domain's project override from wherever its version keeps it.
   *
   * The Domain module changed how domain_config stores overrides between its
   * 2.x and 3.x lines, and both support Drupal 11:
   *
   * - 2.x writes a config object named domain.config.DOMAIN_ID.NAME
   * - 3.x writes NAME into a config collection named domain.DOMAIN_ID
   *
   * Reading the storage covers both without naming a service. The service
   * differs too — domain_config.overrider against
   * domain.config_factory_override — and only the 3.x one can be asked about
   * a domain other than the active one. Asking the 2.x service would mean
   * calling setDomain() on a shared singleton mid-request, which changes what
   * every other config read in that request resolves to.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   * @param string $domainId
   *   The domain id.
   *
   * @return \Generator
   *   Candidate project names, most specific first.
   */
  protected function readDomainOverride($container, string $domainId) : \Generator {
    // Domain 3.x.
    if ($container->has('config.storage')) {
      $collection = $container->get('config.storage')->createCollection('domain.' . $domainId);
      $data = $collection->read('quant_api.settings');
      yield is_array($data) ? ($data['api_project'] ?? NULL) : NULL;
    }

    // Domain 2.x.
    yield $container->get('config.factory')
      ->get('domain.config.' . $domainId . '.quant_api.settings')
      ->get('api_project');
  }

}
