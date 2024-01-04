<?php

namespace Drupal\quant\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\quant\Utility;

/**
 * Provides a Quant page information block.
 *
 * @Block(
 *  id = "quant_page_info_block",
 *  admin_label = @Translation("Quant Page Info"),
 * )
 */
class QuantPageInfoBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {

    return [
      '#markup' => Utility::getPageInfo(),
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
