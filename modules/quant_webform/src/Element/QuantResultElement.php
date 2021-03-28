<?php

namespace Drupal\quant_webform\Element;

use Drupal\Core\Render\Element\FormElement;

/**
 * Provides a 'quant_result_element' element.
 *
 * @FormElement("quant_result_element")
 */
class QuantResultElement extends FormElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return ['#theme' => 'quant_form_result'];
  }

}
