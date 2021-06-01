<?php

namespace Drupal\quant\Event;

use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form event.
 */
interface ConfigFormEventInterface {

  /**
   * Build the config form event.
   *
   * @param Drupal\Core\Form\FormStateInterface $form_state
   *   The configuration values.
   */
  public function __construct(FormStateInterface $form_state = NULL);

  /**
   * Accessor for the form state.
   *
   * @return Drupal\Core\Form\FormStateInterface
   *   The form state.
   */
  public function getFormState();

  /**
   * A queue to add the item to.
   *
   * @return Drupal\Core\Queue\QueueInterface
   *   The queue that will be used.
   */
  public function getQueue();

  /**
   * Queue an item for seeding.
   */
  public function queueItem(array $data = []);

}
