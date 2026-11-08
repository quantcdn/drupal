<?php

namespace Drupal\Tests\quant\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Deleting content removes every URL of it from the static site.
 *
 * A deleted published node used to send its /node/N redirect again (the
 * entity is still published while it is deleted), so /node/N kept a 301 to
 * a page that no longer exists.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class EntityDeleteUnpublishTest extends KernelTestBase {

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
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['quant', 'quant_api']);
    // Delete handling needs only quant_enabled. Push on save stays off, so
    // creating the test content queues no shutdown push.
    $this->config('quant.settings')
      ->set('quant_enabled', TRUE)
      ->set('quant_enabled_nodes', FALSE)
      ->set('quant_enabled_taxonomy', FALSE)
      ->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    Vocabulary::create(['vid' => 'topic', 'name' => 'Topic'])->save();

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(QuantRedirectEvent::UPDATE, function (QuantRedirectEvent $event) {
      $this->events[] = 'redirect ' . $event->getSourceUrl() . ' -> ' . $event->getDestinationUrl();
    });
    $dispatcher->addListener(QuantEvent::UNPUBLISH, function (QuantEvent $event) {
      $this->events[] = 'unpublish ' . $event->getLocation();
    });
  }

  /**
   * A deleted published node loses its page and its /node/N redirect.
   */
  public function testDeletedNodeUnpublishesInternalPath() : void {
    $node = Node::create(['type' => 'page', 'title' => 'Temporary', 'status' => 1]);
    $node->save();
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => '/temporary', 'langcode' => 'und'])->save();
    $this->events = [];

    $node->delete();

    $this->assertContains('unpublish /temporary', $this->events);
    $this->assertContains('unpublish /node/' . $node->id(), $this->events);
    $this->assertEmpty(preg_grep('/^redirect /', $this->events), 'No redirect is sent for deleted content.');
  }

  /**
   * A deleted published term loses its page and its /taxonomy/term/N path.
   */
  public function testDeletedTermUnpublishesInternalPath() : void {
    $term = Term::create(['vid' => 'topic', 'name' => 'Drought', 'status' => 1]);
    $term->save();
    PathAlias::create(['path' => '/taxonomy/term/' . $term->id(), 'alias' => '/topics/drought', 'langcode' => 'und'])->save();
    $this->events = [];

    $term->delete();

    $this->assertContains('unpublish /topics/drought', $this->events);
    $this->assertContains('unpublish /taxonomy/term/' . $term->id(), $this->events);
    $this->assertEmpty(preg_grep('/^redirect /', $this->events), 'No redirect is sent for deleted content.');
  }

}
