<?php

namespace Drupal\Tests\quant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A changed alias keeps the old URL working on the static site.
 *
 * Without the Redirect module, an editor who renames a page (for example
 * when Pathauto rebuilds the alias from a new title) used to leave a 404 at
 * the old URL. The old alias now gets a 301 to the new one.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class AliasChangeRedirectTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'views',
    'path_alias',
    'quant',
    'quant_api',
  ];

  /**
   * The redirects and unpublishes dispatched, in order.
   *
   * @var string[]
   */
  protected array $events = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp() : void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['quant_api']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(QuantRedirectEvent::UPDATE, function (QuantRedirectEvent $event) {
      $this->events[] = 'redirect ' . $event->getSourceUrl() . ' -> ' . $event->getDestinationUrl() . ' ' . $event->getStatusCode();
    });
    $dispatcher->addListener(QuantEvent::UNPUBLISH, function (QuantEvent $event) {
      $this->events[] = 'unpublish ' . $event->getLocation();
    });
  }

  /**
   * Renaming a published page unpublishes the old URL and redirects it.
   */
  public function testRenamedPublishedPageRedirects() : void {
    $alias = $this->pageWithAlias(TRUE, '/news/old-title');
    $this->events = [];
    $alias->setAlias('/news/new-title')->save();

    $this->assertSame([
      'unpublish /news/old-title',
      'redirect /news/old-title -> /news/new-title 301',
    ], $this->events);
  }

  /**
   * An unpublished page gets no redirect to a URL that is not on the site.
   */
  public function testRenamedUnpublishedPageDoesNotRedirect() : void {
    $alias = $this->pageWithAlias(FALSE, '/news/draft-title');
    $this->events = [];
    $alias->setAlias('/news/draft-title-2')->save();

    $this->assertSame(['unpublish /news/draft-title'], $this->events);
  }

  /**
   * An alias for a path that is not a node or term gets no redirect.
   */
  public function testOtherPathsDoNotRedirect() : void {
    $alias = PathAlias::create(['path' => '/user/login', 'alias' => '/sign-in', 'langcode' => 'und']);
    $alias->save();
    $this->events = [];
    $alias->setAlias('/log-in')->save();

    $this->assertSame(['unpublish /sign-in'], $this->events);
  }

  /**
   * Creates a page and its alias.
   */
  protected function pageWithAlias(bool $published, string $path) : PathAlias {
    $node = Node::create(['type' => 'page', 'title' => 'A page', 'status' => $published]);
    $node->save();
    $alias = PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $path, 'langcode' => 'und']);
    $alias->save();
    return $alias;
  }

}
