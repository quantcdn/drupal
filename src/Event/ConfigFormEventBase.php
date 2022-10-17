<?php

namespace Drupal\quant\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Form\FormStateInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;

/**
 * Configuration form event.
 */
class ConfigFormEventBase extends Event implements ConfigFormEventInterface {

  /**
   * The class to use for a queue item.
   *
   * @var string
   */
  protected $queueItemClass = RouteItem::class;

  /**
   * The form state for the event.
   *
   * @var Drupal\Core\Form\FormStateInterface
   */
  protected $formState;

  /**
   * The queue that is used to seed data.
   *
   * @var Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

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

  /**
   * {@inheritdoc}
   */
  public function getQueue() {
    if (empty($this->queue)) {
      $queue_factory = \Drupal::service('queue');
      $this->queue = $queue_factory->get('quant_seed_worker');
    }
    return $this->queue;
  }

  /**
   * {@inheritdoc}
   */
  public function queueItem(array $data = []) {
    if (!class_exists($this->queueItemClass)) {
      throw new \Exception('Invalid queue item class ' . $this->queueItemClass);
    }

    $item = new $this->queueItemClass($data);
    $this->getQueue()->createItem($item);
  }

}
