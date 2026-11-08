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
  ) {}

  /**
   * The paths of the pages that show a Webform.
   *
   * The Webform's own page (when it has one) and every published node whose
   * Webform field references it.
   *
   * @return string[]
   *   Paths such as /form/contact or /contact-us.
   */
  public function pages(WebformInterface $webform): array {
    $paths = [];
    if ($webform->getSetting('page')) {
      $paths[] = $webform->toUrl('canonical')->toString(TRUE)->getGeneratedUrl();
    }
    foreach ($this->referencingNodes($webform) as $node) {
      $paths[] = $node->toUrl('canonical')->toString(TRUE)->getGeneratedUrl();
    }
    return array_values(array_unique(array_map([self::class, 'path'], $paths)));
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
   * Turns Quant Forms off on each page. Submissions stay in Quant.
   *
   * @param string[] $paths
   *   The pages, from pages() before the Webform changed.
   */
  public function disable(array $paths): void {
    foreach ($paths as $path) {
      $this->client->sendFormConfig($path, FALSE);
    }
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
