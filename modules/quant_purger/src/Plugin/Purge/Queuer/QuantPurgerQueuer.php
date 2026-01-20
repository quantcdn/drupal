<?php

namespace Drupal\quant_purger\Plugin\Purge\Queuer;

use Drupal\purge\Plugin\Purge\Queuer\QueuerBase;
use Drupal\purge\Plugin\Purge\Queuer\QueuerInterface;

/**
 * Quant Purger Queuer.
 *
 * @PurgeQueuer(
 *   id = "quant_purger_queuer",
 *   label = @Translation("Quant Purger Queuer"),
 *   description = @Translation("Update Quant queue based on content updates."),
 *   enable_by_default = true,
 *   types = {"tag"},
 *   configform = "\Drupal\quant_purger\Form\QuantPurgerQueuerConfigForm",
 * )
 */
class QuantPurgerQueuer extends QueuerBase implements QueuerInterface {}
