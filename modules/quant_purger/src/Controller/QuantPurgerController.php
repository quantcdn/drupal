<?php

namespace Drupal\quant_purger\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Quant Purger controller.
 */
class QuantPurgerController extends ControllerBase {

  /**
   * Quant Purger page with helpful information.
   */
  public function content() {
    // Check if database table is empty.
    $count = \Drupal::database()->select('purge_queuer_quant')->countQuery()->execute()->fetchField();
    $text = '';
    if ($count) {
      $text = $this->t('The Quant Purger registry database table has :count entries.<br/><br/>', [':count' => $count]);
    }
    else {
      $warning = $this->t("The Quant Purger registry database table is empty which can happen if you haven't configured this module or run a seed yet.<br/>This will not affect the normal Quant functionality, but does affect the purger functionality.");
      \Drupal::messenger()->addWarning($warning);
    }

    if (\Drupal::moduleHandler()->moduleExists('purge_ui')) {
      $text .= $this->t('You can add and update the Quant Purger settings via the <a href=":url">Purge UI</a>.<br/><br/>', [':url' => '/admin/config/development/performance/purge']);
    }
    else {
      $text .= $this->t('Enabling the <a href=":url">Purge UI module</a> will allow you to configure the Quant Purger module through the UI.<br/><br/>', [':url' => 'https://www.drupal.org/project/purge']);
    }
    $text .= $this->t('For more information about the Quant Purger module, refer to the <a href=":url">documentation</a>.', [':url' => 'https://docs.quantcdn.io/docs/integrations/drupal/purger']);
    $build = [
      '#markup' => $text,
    ];
    return $build;
  }

}
