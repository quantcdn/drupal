<?php

namespace Drupal\quant_purger\Plugin\Purge\Queuer;

use Drupal\purge\Plugin\Purge\Queuer\QueuerBase;
use Drupal\purge\Plugin\Purge\Queuer\QueuerInterface;

/**
 * Quant purger.
 *
 * @PurgeQueuer(
 *   id = "quant",
 *   label = @Translation("Purge Quant"),
 *   description = @Translation("Queue impacted content updates."),
 *   enable_by_default = true,
 *   types = {"tag"},
 *   configform = "\Drupal\quant_purger\Form\ConfigurationForm",
 * )
 */
class QuantPurgerPlugin extends QueuerBase implements QueuerInterface {}
