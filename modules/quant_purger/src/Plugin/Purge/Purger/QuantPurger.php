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
 *   label = @Translation("Quant Purger"),
 *   configform = "\Drupal\quant_purger\Form\QuantPurgerConfigForm",
 *   cooldown_time = 0.2,
 *   description = @Translation("Purger that sends invalidation expressions from your Drupal instance to the QuantCDN platform."),
 *   multi_instance = FALSE,
 *   types = {"everything", "path", "tag"},
 * )
 */
class QuantPurger extends QuantPurgerBase implements PurgerInterface {

  /**
   * {@inheritdoc}
   */
  public function invalidate(array $invalidations) {

    $processed = $this->processInvalidations($invalidations);

    if ($processed['everything']) {
      $this->invalidateEverything($invalidations);
      return;
    }

    if (!empty($processed['paths'])) {
      $this->invalidatePaths($processed['paths']);
    }

    if (!empty($processed['tags'])) {
      $this->invalidateTags($processed['tags']);
    }

  }

  /**
   * Invalidate with the path '/*' to purge the entire project cache.
   *
   * @param array $invalidations
   *   This takes in an array of Invalidation objects, processing them all in a
   *   loop, generally from the purge queue.
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

  /**
   * Invalidate path-based invalidations.
   *
   * @param array $invalidations
   *   Array of Invalidation objects to process.
   */
  public function invalidatePaths(array $invalidations) {

    foreach ($invalidations as $invalidation) {
      try {
        $path = '/' . $invalidation->getExpression();
        $this->logger()->debug('[path] Purging path: ' . $path);
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
   * Invalidate tag-based invalidations.
   *
   * @param array $invalidations
   *   Array of Invalidation objects to process.
   */
  public function invalidateTags(array $invalidations) {
    try {
      $this->logger()->debug('[tags] Purging tags: ' . implode(' ', $invalidations));

      $tags = [];
      foreach ($invalidations as $invalidation) {
        $tags[] = Hash::cacheTags([$invalidation->getExpression()])[0];
      }

      $this->purgeTags($tags);
      $invalidation->setState(InvalidationInterface::SUCCEEDED);
    }
    catch (\Exception $e) {
      $this->logger()->notice('Error attempting to purge cache path: ' . $e->getMessage());
      error_log($e->getMessage());
      $invalidation->setState(InvalidationInterface::FAILED);
    }
  }

}
