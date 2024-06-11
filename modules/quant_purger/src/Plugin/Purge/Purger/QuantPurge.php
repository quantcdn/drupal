<?php

namespace Drupal\quant_purger\Plugin\Purge\Purger;

use Drupal\purge\Plugin\Purge\Purger\PurgerInterface;
use Drupal\purge\Plugin\Purge\Invalidation\InvalidationInterface;
use Drupal\quant_purger\Entity\Hash;

/**
 * Quant Purger.
 *
 * @PurgePurger(
 *   id = "quant_purger",
 *   label = @Translation("QuantCDN Purger"),
 *   configform = "\Drupal\quant_purger\Form\QuantPurgeForm",
 *   cooldown_time = 0.2,
 *   description = @Translation("Purger that sends invalidation expressions from your Drupal instance to the QuantCDN platform."),
 *   multi_instance = FALSE,
 *   types = {"tag", "everything", "path"},
 * )
 */
class QuantPurge extends QuantPurgeBase implements PurgerInterface {

  /**
   * {@inheritdoc}
   */
  public function invalidate(array $invalidations) {

    $filtered_validations = $this->processInvalidations($invalidations);

    if ($filtered_validations['everything']) {
      $this->invalidateEverything($invalidations);
      return;
    }

    if (!empty($filtered_validations['tags'])) {
      $this->invalidateTags($filtered_validations['tags']);
    }

    if (!empty($filtered_validations['paths'])) {
      $this->invalidatePaths($filtered_validations['paths']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $invalidations) {
    $tags = [];
    foreach ($invalidations as $invalidation) {
      $tags[] = Hash::cacheTags([$invalidation->getExpression()])[0];
    }

    try {
      $this->logger()->debug('[tags] Purging tags: ' . implode(' ', $tags));
      $this->purgeTags($tags);
      $invalidation->setState(InvalidationInterface::SUCCEEDED);
    }
    catch (\Exception $e) {
      $this->logger()->notice('Error attempting to purge cache path: ' . $e->getMessage());
      error_log($e->getMessage());
      $invalidation->setState(InvalidationInterface::FAILED);
    }
  }

  /**
   * Invalidate path-based invalidations in a loop.
   *
   * @param array $invalidations
   *   This takes in an array of Invalidation, processing them all in a loop,
   *   generally from the purge queue.
   */
  public function invalidatePaths(array $invalidations) {

    foreach ($invalidations as $invalidation) {
      try {
        $path = '/' . $invalidation->getExpression();
        $this->logger()->debug('[path] Purging path invalidation: ' . $path);
        $this->purgePath($path);
        $invalidation->setState(InvalidationInterface::SUCCEEDED);
      }
      catch (\Exception $e) {
        $this->logger()->notice('Error attempting to purge cache path: ' . $e->getMessage());
        error_log($e->getMessage());
        $invalidation->setState(InvalidationInterface::FAILED);
      }
    }
  }

  /**
   * Invalidate with the path '/*' to purge the entire project cache.
   *
   * @param array $invalidations
   *   This takes in an array of Invalidation, processing them all in a loop,
   *   generally from the purge queue.
   */
  public function invalidateEverything(array $invalidations) {

    try {
      $this->logger()->debug('[everything] Purging entire site cache (/*)');
      $this->purgePath('/*');
      foreach ($invalidations as $invalidation) {
        $invalidation->setState(InvalidationInterface::SUCCEEDED);
      }
    }
    catch (\Exception $e) {
      $this->logger()->notice('Error attempting to purge entire cache: ' . $e->getMessage());
      error_log($e->getMessage());
      foreach ($invalidations as $invalidation) {
        $invalidation->setState(InvalidationInterface::FAILED);
      }
    }
  }

}
