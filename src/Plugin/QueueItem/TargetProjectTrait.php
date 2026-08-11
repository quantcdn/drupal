<?php

namespace Drupal\quant\Plugin\QueueItem;

/**
 * Records the Quant project a queue item was created for.
 *
 * Queue items are created in one process and sent in another. On a site that
 * serves several domains from one Drupal instance, each domain publishes to
 * a different project, and the worker resolves that project from whatever
 * domain context it happens to boot in. Without a record of the intended
 * destination, an item queued for one client can be published to another
 * client's project.
 *
 * The stamp is taken at enqueue time, when the domain context is known to be
 * correct, and is checked again before the item is sent.
 *
 * @see \Drupal\quant\Plugin\QueueWorker\QuantSeedWorker
 *
 * @ingroup quant
 */
trait TargetProjectTrait {

  /**
   * The Quant project this item was queued for.
   *
   * @var string|null
   */
  protected $targetProject = NULL;

  /**
   * Records the project resolved in the current domain context.
   */
  protected function stampTargetProject() : void {
    $this->targetProject = \Drupal::config('quant_api.settings')->get('api_project') ?: NULL;
  }

  /**
   * Returns the project this item was queued for.
   *
   * @return string|null
   *   The project machine name, or NULL for items queued before this stamp
   *   existed. A NULL stamp is not checked, so old items keep working.
   */
  public function getTargetProject() : ?string {
    return $this->targetProject;
  }

}
