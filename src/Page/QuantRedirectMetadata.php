<?php

namespace Drupal\quant\Page;

use Drupal\Core\Controller\ControllerBase;
use Drupal\views\Views;

/**
 * Page controller for the redirect metadata page.
 */
class QuantRedirectMetadata extends ControllerBase {

  /**
   * Page callback for the redirect metadata page.
   *
   * If the redirect module is enabled and the metadata view exists, then show
   * the view. Otherwise, show a help message.
   *
   * @return array
   *   A render array.
   */
  public function build() {

    $view = NULL;
    $message = '';
    // Check module is installed.
    if (!\Drupal::moduleHandler()->moduleExists('redirect')) {
      $message = $this->t('The redirect module is not enabled.');
    }
    // Check view is found.
    else {
      $view = Views::getView('quant_metadata_redirect');
      if (!$view) {
        $message = $this->t('The redirect metadata view was not found.');
      }
    }

    // Show message.
    if (!empty($message)) {
      $build['info'] = [
        '#type' => 'markup',
        '#markup' => $message,
      ];
      return $build;
    }

    // Render the view display.
    $view->setDisplay('quant_metadata_redirect_page');
    $view->preExecute();
    $view->execute();

    return $view->buildRenderable();
  }

}
