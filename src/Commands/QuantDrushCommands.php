<?php

namespace Drupal\quant\Commands;

use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drupal\Core\Form\FormState;
use Drupal\quant\CliDomainContext;
use Drupal\quant\PublishGuard;
use Drupal\quant\Seed;
use Drupal\quant\Event\CollectEntitiesEvent;
use Drupal\quant\Event\CollectFilesEvent;
use Drupal\quant\Event\CollectRedirectsEvent;
use Drupal\quant\Event\CollectRoutesEvent;
use Drupal\quant\Event\CollectTaxonomyTermsEvent;
use Drupal\quant\Event\QuantCollectionEvents;
use Drupal\quant\QuantQueueFactory;

/**
 * A drush command file.
 *
 * @package Drupal\quant\Commands
 */
class QuantDrushCommands extends DrushCommands {

  /**
   * Tracks currently running processes via proc_open.
   *
   * @var runningProcs
   */
  private $runningProcs = [];

  /**
   * Returns lock file location (project specific).
   *
   * Reads through the overridable config factory. getEditable() returns the
   * base values only, which on a multi-domain site gives every domain the
   * same lock file: the first seed run blocks all the others.
   */
  private function getLockFileLocation() {
    $config = \Drupal::config('quant_api.settings');
    return sys_get_temp_dir() . '/' . $config->get('api_project') . '_quant_seed_worker.lock';
  }

  /**
   * Returns the options forked workers must inherit from this process.
   *
   * Forked workers start as bare drush processes that share nothing with
   * their parent. Without --uri the worker boots on the default domain, so
   * per-domain config overrides resolve to the base project and every
   * domain's content is published there.
   *
   * @return string
   *   Options to append to the forked command, escaped for the shell.
   */
  private function getForkOptions() : string {
    $options = [];
    $bootstrapManager = Drush::bootstrapManager();

    if ($uri = $bootstrapManager->getUri()) {
      $options[] = '--uri=' . escapeshellarg($uri);
    }

    if ($root = $bootstrapManager->getRoot()) {
      $options[] = '--root=' . escapeshellarg($root);
    }

    return $options ? ' ' . implode(' ', $options) : '';
  }

  /**
   * Returns path to drush binary for process forking.
   *
   * Priority order: $CWD, $DRUSH_PATH, $PATH, vendor/bin/drush.
   */
  private function getDrushPath() {

    $testPaths = [
      __DIR__ . '/drush',
      getenv('DRUSH_PATH'),
    ];

    foreach (explode(':', getenv('PATH')) as $p) {
      $testPaths[] = $p . '/drush';
    }

    // Support composer location in any number of places.
    $testPaths += [
      DRUPAL_ROOT . '/../vendor/bin/drush',
      DRUPAL_ROOT . '/vendor/bin/drush',
      getenv('HOME') . '/.composer/vendor/bin/drush',
    ];

    foreach ($testPaths as $path) {
      if (empty($path)) {
        continue;
      }

      if (file_exists($path) && is_executable($path)) {
        return $path;
      }
    }

    throw new \Exception('Unable to find a valid drush binary, please specify $DRUSH_PATH.');

  }

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
    // Resolve per-domain config before anything reads the project name.
    $domainId = CliDomainContext::initialize();

    $this->output()->writeln("<info>Forking seed worker.</info>");
    $drushPath = $this->getDrushPath();
    $lockFilePath = $this->getLockFileLocation();
    $cmd = $drushPath . ' queue:run quant_seed_worker' . $this->getForkOptions();
    $this->output()->writeln("<comment>Using drush binary at $drushPath. Override with \$DRUSH_PATH if required.</comment>");

    $project = CliDomainContext::getActiveProject();
    if ($domainId) {
      $this->output()->writeln("<info>Active domain: {$domainId}. Publishing to project: {$project}.</info>");
    }

    // Bail if another run is in progress.
    if (file_exists($lockFilePath)) {
      $this->output()->writeln("<info>Seeding bailed. Another seed run is in progress (lockfile is present: {$lockFilePath})</info>");
      $this->output()->writeln("<info>Run drush quant:unlock-queue to manually unlock the queue.</info>");
      return;
    }
    else {
      // No lock currently present. Create new lock file.
      file_put_contents($lockFilePath, NULL);
    }

    for ($i = 0; $i < $options['threads']; $i++) {
      $this->runningProcs[] = proc_open($cmd, [], $pipes, NULL, NULL, ['bypass_shell' => TRUE]);
    }

    // Wait until commands complete.
    foreach ($this->runningProcs as $proc) {
      $procStatus = proc_get_status($proc);

      while ($procStatus['running']) {
        $procStatus = proc_get_status($proc);
      }
    }

    // Remove lock file.
    unlink($lockFilePath);

    $this->output()->writeln("<info>Seeding complete.</info>");

  }

  /**
   * Unlock Quant queue.
   *
   * @command quant:unlock-queue
   * @aliases quant-queue-unlock
   * @usage quant:unlock-queue
   */
  public function unlock($options = []) {
    // The lock file is named after the resolved project, so the domain must
    // be negotiated before the path can be built.
    CliDomainContext::initialize();
    $lockFilePath = $this->getLockFileLocation();
    unlink($lockFilePath);

    $this->output()->writeln("Unlocked Quant queue.");
  }

  /**
   * Clear the Quant queue.
   *
   * @command quant:clear-queue
   * @aliases quant-queue-clear
   * @usage quant:clear-queue
   */
  public function clear($options = []) {
    $queue_factory = QuantQueueFactory::getInstance();
    $queue = $queue_factory->get('quant_seed_worker');
    $queue->deleteQueue();
    $this->output()->writeln("Removed all items from Quant queue.");
  }

  /**
   * Drush command to prepare the seed.
   *
   * @command quant:seed-queue
   * @aliases quant-queue-seed
   * @options reset
   *   Reset the current queue.
   * @usage quant:seed-queue
   */
  public function prepare($options = ['reset' => 'true']) {
    // Resolve per-domain config before the seed settings are read, so that
    // each domain seeds with its own bundles, routes and file paths.
    $domainId = CliDomainContext::initialize();

    $this->output()->writeln("Preparing seed...");

    if ($domainId) {
      $project = CliDomainContext::getActiveProject();
      $this->output()->writeln("Active domain: {$domainId}. Target project: {$project}.");
    }

    // Stale node grants make every domain serve every page, so this seed
    // would collect another client's content and publish it here. It is
    // routed correctly, so nothing downstream reports a problem.
    if (PublishGuard::nodeGrantsAreStale()) {
      $this->output()->writeln('<error>Domain Access grants are out of date. Every domain currently serves every page, so this seed will publish other sites\' content into this project. Rebuild permissions at /admin/reports/status first.</error>');
    }

    $config = \Drupal::config('quant.settings');

    $queue_factory = QuantQueueFactory::getInstance();
    $queue = $queue_factory->get('quant_seed_worker');

    $dispatcher = \Drupal::service('event_dispatcher');

    if ($options['reset'] == 'true') {
      $queue->deleteQueue();
    }

    $assets = [];
    $routes = [];

    // Prepare the form state based on the config.
    $form_state = new FormState();
    $config_keys = [
      'entity_node',
      'entity_node_languages',
      'entity_node_bundles',
      'entity_node_revisions',
      'entity_taxonomy_term',
      'theme_assets',
      'views_pages',
      'redirects',
      'routes',
      'routes_textarea',
      'file_paths',
      'file_paths_textarea',
      'robots',
      'export_sitemap',
      'lunr',
    ];

    foreach ($config_keys as $key) {
      $form_state->setValue($key, $config->get($key));
    }

    if ($config->get('lunr')) {
      $assets = array_merge($assets, Seed::findLunrAssets());
      $routes = array_merge($routes, Seed::findLunrRoutes());
    }

    if ($form_state->getValue('routes_textarea')) {
      foreach (explode(PHP_EOL, $form_state->getValue('routes')) as $route) {
        if (strpos((trim($route)), '/') !== 0) {
          continue;
        }
        $routes[] = trim($route);
      }
    }

    if ($form_state->getValue('redirects')) {
      // Collect the redirects for the seed.
      $event = new CollectRedirectsEvent($form_state);
      $dispatcher->dispatch($event, QuantCollectionEvents::REDIRECTS);
    }

    if ($form_state->getValue('entity_node') || $form_state->getValue('entity_node_revisions')) {
      $event = new CollectEntitiesEvent($form_state);
      $dispatcher->dispatch($event, QuantCollectionEvents::ENTITIES);
    }

    if ($form_state->getValue('entity_taxonomy_term')) {
      $event = new CollectTaxonomyTermsEvent($form_state);
      $dispatcher->dispatch($event, QuantCollectionEvents::TAXONOMY_TERMS);
    }

    $event = new CollectRoutesEvent($form_state);
    $dispatcher->dispatch($event, QuantCollectionEvents::ROUTES);

    foreach ($routes as $route) {
      $event->queueItem($route);
    }

    $event = new CollectFilesEvent($form_state);
    $dispatcher->dispatch($event, QuantCollectionEvents::FILES);

    foreach ($assets as $asset) {
      $event->queueItem($asset);
    }

    $this->output()->writeln('Successfully added [' . $queue->numberOfItems() . '] to the queue');
  }

}
