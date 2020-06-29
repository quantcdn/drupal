<?php

namespace Drupal\quant\Event;

use Symfony\Component\EventDispatcher\Event;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form event.
 */
class ConfigFormEventBase extends Event {

  /**
   * The form state for the event.
   *
   * @var Drupal\Core\Form\FormStateInterface
   */
  protected $formState;

  /**
   * {@inheritdoc}
   */
  public function __construct(FormStateInterface $form_state = NULL) {
    $this->formState = $form_state;
  }

  /**
   * Accessor for the form state.
   *
   * @return Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  public function getFormState() {
    return $this->formState;
  }

}
