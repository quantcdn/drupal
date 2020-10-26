<?php

namespace Drupal\quant_purger\Plugin\Purge\Queuer;

use Drupal\purge\Plugin\Purge\Queuer\QueuerBase;
use Drupal\purge\Plugin\Purge\Queuer\QueuerInterface;

/**
 * Quant purger.
 *
 * @PurgePurger(
 *   id = "quant_purger",
 *   label = @Translation("Quant Purger"),
 *   description = @Translation("Queue impacted content updates."),
 *   enable_by_default = true,
 *   types = {"tag"}
 * )
 */
class QuantPurgerPlugin extends QueuerBase implements QueuerInterface {}
