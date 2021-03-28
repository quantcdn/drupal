<?php

namespace Drupal\quant_webform\Plugin\WebformElement;

use Drupal\webform\Plugin\WebformElementBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Quant Result element.
 *
 * @WebformElement(
 *   id = "quant_result_element",
 *   label = @Translation("Quant Submission result"),
 *   description = @Translation("Submission result (success/failure message) will display here."),
 *   category = @Translation("Quant"),
 * )
 */
class QuantResult extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  public function getDefaultProperties() {
    return ['title' => ''];
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    return $form;
  }

}
