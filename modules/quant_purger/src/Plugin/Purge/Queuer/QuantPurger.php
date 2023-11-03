<?php

namespace Drupal\quant_purger\Plugin\Purge\Queuer;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Queues URLs with Quant when Drupal invalidates cache tags.
 */
class QuantPurger implements CacheTagsInvalidatorInterface, ContainerAwareInterface {
  use ContainerAwareTrait;

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

    $paths = $this->registry->getPaths($tags);

    foreach ($tags as $tag) {
      $this->invalidatedTags[] = $tag;
    }

    foreach ($paths as $path) {
      $this->quantSeedQueue->createItem(new RouteItem(['route' => $path]));
    }
  }

}
