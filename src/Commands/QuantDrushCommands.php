<?php

namespace Drupal\quant\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\Form\FormState;
use Drupal\quant\Seed;
use Drupal\quant\Event\CollectEntitiesEvent;
use Drupal\quant\Event\CollectFilesEvent;
use Drupal\quant\Event\CollectRedirectsEvent;
use Drupal\quant\Event\CollectRoutesEvent;
use Drupal\quant\Event\QuantCollectionEvents;

/**
 * A drush command file.
 *
 * @package Drupal\quant\Commands
 */
class QuantDrushCommands extends DrushCommands {

  /**
   * Drush command that executes the Quant queue.
   *
   * @command quant:run-queue
   * @aliases quant-queue-run
   * @option threads
   *   Number of threads to use (default 5)
   * @usage quant:run-queue --threads=5
   */
  public function message($options = ['threads' => 5]) {
    $this->output()->writeln("Forking seed worker.");
    for ($i = 0; $i < $options['threads']; $i++) {
      $cmd = 'drush queue:run quant_seed_worker';
      $process = proc_open($cmd, [], $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
    }
  }

  /**
   * Clear the quant queue.
   *
   * @command quant:clear
   * @aliases quant-clear
   * @usage quant:clear
   */
  public function clear($options = []) {
    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get('quant_seed_worker');

    if ($options['reset'] == 'true') {
      $queue->deleteQueue();
    }
  }

  /**
   * Drush command to prepare the seed.
   *
   * @command quant:seed
   * @aliases quant-seed
   * @options reset
   *   Reset the current queue.
   * @usage quant:seed
   */
  public function prepare($options = ['reset' => 'true']) {
    $this->output()->writeln("Preparing seed...");

    $config = \Drupal::configFactory()->getEditable('quant.settings');

    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get('quant_seed_worker');

    $dispatcher = \Drupal::service('event_dispatcher');

    if ($options['reset'] == 'true') {
      $queue->deleteQueue();
    }

    $assets = [];
    $routes = [];
    $form_state = new FormState();

    if ($config->get('lunr')) {
      $assets = array_merge($assets, Seed::findLunrAssets());
      $routes = array_merge($routes, Seed::findLunrRoutes());
    }

    if ($config->get('routes_textarea')) {
      foreach (explode(PHP_EOL, $config->get('routes')) as $route) {
        if (strpos((trim($route)), '/') !== 0) {
          continue;
        }
        $routes[] = trim($route);
      }
    }

    if ($config->get('redirects')) {
      $event = new CollectRedirectsEvent($form_state);
      $dispatcher->dispatch(QuantCollectionEvents::REDIRECTS, $event);
    }

    if ($config->get('entity_node')) {
      $event = new CollectEntitiesEvent($form_state);
      $dispatcher->dispatch(QuantCollectionEvents::ENTITIES, $event);
    }

    $event = new CollectRoutesEvent($form_state);
    $dispatcher->dispatch(QuantCollectionEvents::ROUTES, $event);

    foreach ($routes as $route) {
      $event->queueItem($route);
    }

    $event = new CollectFilesEvent($form_state);
    $dispatcher->dispatch(QuantCollectionEvents::FILES, $event);

    foreach ($assets as $asset) {
      $event->queueItem($asset);
    }

    $this->output()->writeln("Done!");
  }

}
