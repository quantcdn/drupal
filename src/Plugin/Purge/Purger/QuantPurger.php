<?php

namespace Drupal\quant\Plugin\Purge\Purger;

/**
 * Quant purger.
 *
 * @PurgePurger(
 *    id = "quant",
 *    label = @Translation("Quant Purger"),
 *    description = @Translation("Purger that builds and publishes related items to Quant."),
 *    multi_instance = TRUE,
 *    types = {"tag"}
 * )
 */
class QuantPurger implements PurgerInterface {

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $invalidate) {

  }

}
