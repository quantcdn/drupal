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
   * Records the project this item must be published to.
   *
   * Defaults to the project the current domain context resolves to. Callers
   * that queue work on behalf of a domain other than the one they are
   * serving — cache invalidation touching every domain that shows a page,
   * for example — pass the project explicitly.
   *
   * @param array $data
   *   The queue item data. An explicit 'target_project' wins.
   */
  protected function stampTargetProject(array $data = []) : void {
    if (!empty($data['target_project'])) {
      $this->targetProject = $data['target_project'];
      return;
    }

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
