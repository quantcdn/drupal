<?php

namespace Drupal\quant_webform;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\QuantQueueFactory;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\webform\WebformInterface;

/**
 * Registers Webforms with Quant Forms by pushing the pages that show them.
 *
 * A push of a page carries the form config inline (QuantFormsSubscriber), so
 * "register" means "push the page again". Disabling has no page to push, so
 * it calls the form endpoint directly.
 */
class QuantFormsRegistrar {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected QuantClientInterface $client,
    protected QuantFormsRegistry $registry,
  ) {}

  /**
   * The paths of the pages that take a Webform's submissions.
   *
   * A Webform with its own page posts there from everywhere, so that page is
   * the only one. Otherwise every published node whose Webform field
   * references it.
   *
   * @return string[]
   *   Paths such as /form/contact or /contact-us.
   */
  public function pages(WebformInterface $webform): array {
    if ($webform->getSetting('page')) {
      return [self::path($webform->toUrl('canonical')->toString(TRUE)->getGeneratedUrl())];
    }
    $paths = [];
    foreach ($this->referencingNodes($webform) as $node) {
      $paths[] = $node->toUrl('canonical')->toString(TRUE)->getGeneratedUrl();
    }
    $paths = array_map([self::class, 'path'], $paths);
    // Pages registered when they published: blocks, paragraphs and Layout
    // Builder placements that no field query finds.
    return array_values(array_unique(array_merge($paths, $this->registry->pagesOf($webform->id()))));
  }

  /**
   * Pushes each page now. For a save: a few pages, the editor waits.
   *
   * @return int
   *   The number of pages pushed.
   */
  public function pushNow(WebformInterface $webform): int {
    $pages = $this->pages($webform);
    foreach ($pages as $path) {
      (new RouteItem(['route' => $path]))->send();
    }
    return count($pages);
  }

  /**
   * Queues each page for the Quant queue (cron or drush quant:run-queue).
   *
   * @return int
   *   The number of pages queued.
   */
  public function queue(WebformInterface $webform): int {
    $queue = QuantQueueFactory::getInstance()->get('quant_seed_worker');
    $pages = $this->pages($webform);
    foreach ($pages as $path) {
      $queue->createItem(new RouteItem(['route' => $path]));
    }
    return count($pages);
  }

  /**
   * Turns Quant Forms off on every page this site registered for a Webform.
   *
   * Only pages in the registry: a page set up by hand in the dashboard is
   * never touched. Submissions stay in Quant.
   *
   * @return int
   *   The number of pages turned off.
   */
  public function disable(WebformInterface $webform): int {
    $paths = $this->registry->pagesOf($webform->id());
    foreach ($paths as $path) {
      if ($this->client->sendFormConfig($path, FALSE)) {
        $this->registry->forget($path);
      }
    }
    return count($paths);
  }

  /**
   * The published nodes with a Webform field that references the Webform.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The nodes.
   */
  protected function referencingNodes(WebformInterface $webform): array {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return [];
    }
    $fields = $this->entityFieldManager->getFieldMapByFieldType('webform')['node'] ?? [];
    $storage = $this->entityTypeManager->getStorage('node');
    $nodes = [];
    foreach (array_keys($fields) as $field) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition($field . '.target_id', $webform->id())
        ->execute();
      $nodes += $storage->loadMultiple($ids);
    }
    return $nodes;
  }

  /**
   * The path part of a generated URL.
   */
  protected static function path(string $url): string {
    return (string) parse_url($url, PHP_URL_PATH);
  }

}
