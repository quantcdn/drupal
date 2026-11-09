<?php

namespace Drupal\quant_webform\Plugin\WebformElement;

use Drupal\quant_webform\ExportContext;
use Drupal\webform\Plugin\WebformElement\WebformWizardPage;

/**
 * A wizard page that a Quant export renders with every other page.
 *
 * Webform renders only the current page and hides the others with
 * #access = FALSE. A static page has no server to render the next page, so
 * an export render shows every page, marked for quant-webform.js, which runs
 * the steps in the browser. A live render is unchanged.
 *
 * Replaces the class of the webform_wizard_page plugin in
 * quant_webform_webform_element_info_alter().
 */
class QuantWizardPage extends WebformWizardPage {

  /**
   * {@inheritdoc}
   */
  public function showPage(array &$element) {
    parent::showPage($element);
    if (ExportContext::isStaticForm($element['#webform'] ?? NULL)) {
      $this->markPage($element);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hidePage(array &$element) {
    if (!ExportContext::isStaticForm($element['#webform'] ?? NULL)) {
      parent::hidePage($element);
      return;
    }
    parent::showPage($element);
    $this->markPage($element);
  }

  /**
   * Marks a page for the browser steps.
   */
  protected function markPage(array &$element): void {
    $element['#attributes']['data-quant-wizard-page'] = $element['#webform_key'] ?? '';
    $element['#attributes']['data-quant-wizard-title'] = (string) ($element['#title'] ?? '');
    $element['#attributes']['class'][] = 'quant-wizard-page';
  }

}
