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
    if (\Drupal::moduleHandler()->moduleExists('purge_ui')) {
      $text = $this->t('You can add or remove cache tags and URLs via the <a href=":url">Purge UI</a>.<br/><br/>', [':url' => '/admin/config/development/performance/purge']);
    }
    else {
      $text = $this->t('Enabling the <a href=":url">Purge UI module</a> will allow you to configure the Quant Purger module through the UI.<br/><br/>', [':url' => 'https://www.drupal.org/project/purge']);
    }
    $text .= $this->t('For more information about the Quant Purger module, refer to the <a href=":url">documentation</a>.', [':url' => 'https://docs.quantcdn.io/docs/integrations/drupal/purger']);
    $build = [
      '#markup' => $text,
    ];
    return $build;
  }

}
