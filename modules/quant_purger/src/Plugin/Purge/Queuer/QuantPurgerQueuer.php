<?php

namespace Drupal\quant_purger\Plugin\Purge\Queuer;

use Drupal\purge\Plugin\Purge\Queuer\QueuerBase;
use Drupal\purge\Plugin\Purge\Queuer\QueuerInterface;

/**
 * Quant Purger Queuer.
 *
 * @PurgeQueuer(
 *   id = "quant",
 *   label = @Translation("Quant Purger Queuer"),
 *   description = @Translation("Queue impacted content updates."),
 *   enable_by_default = true,
 *   types = {"tag"},
 *   configform = "\Drupal\quant_purger\Form\QuantPurgerQueuerConfigForm",
 * )
 */
// @todo Change id to 'quant_purger_queuer' which requires an update hook.
class QuantPurgerQueuer extends QueuerBase implements QueuerInterface {}
