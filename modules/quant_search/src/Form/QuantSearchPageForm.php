<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for the Quant Search page add and edit forms.
 */
class QuantSearchPageForm extends EntityForm {

  /**
   * Constructs an QuantSearchPageForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entityTypeManager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $page = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $page->label(),
      '#description' => $this->t("Administrative label for the search page."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $page->id(),
      '#machine_name' => [
        'exists' => [$this, 'exist'],
      ],
      '#disabled' => !$page->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('title'),
      '#description' => $this->t("Page title."),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->get('description'),
      '#description' => $this->t('Search page description.'),
    ];

    $form['route'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Route'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->get('route'),
      '#description' => $this->t('Page route for the search page.'),
      '#required' => TRUE,
    ];

    // You will need additional form elements for your custom properties.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $page = $this->entity;
    $status = $page->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addMessage($this->t('The %label search page created.', [
        '%label' => $page->label(),
      ]));
    }
    else {
      $this->messenger()->addMessage($this->t('The %label search page updated.', [
        '%label' => $page->label(),
      ]));
    }

    \Drupal::service('router.builder')->rebuild();
    $form_state->setRedirect('entity.quant_search_page.collection');
  }

  /**
   * Helper function to check whether an search page configuration entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('quant_search_page')->getQuery()
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }

}
