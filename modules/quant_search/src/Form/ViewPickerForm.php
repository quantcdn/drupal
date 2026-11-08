<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Chooses a View page to turn into a Quant search page.
 */
class ViewPickerForm extends FormBase {

  /**
   * The converter.
   *
   * @var \Drupal\quant_search\ViewSearchPageConverter
   */
  protected $converter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->converter = $container->get('quant_search.view_converter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_search_view_picker';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $candidates = $this->converter->candidates();
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('A static site cannot run a View with filters. Choose a View page; the module proposes a Quant search page with the same content types and filters. You review the proposal before anything is created.') . '</p>',
    ];
    if ($candidates === []) {
      $form['none'] = ['#markup' => '<p>' . $this->t('There is no View page that lists content.') . '</p>'];
      return $form;
    }
    $form['view'] = [
      '#type' => 'select',
      '#title' => $this->t('View page'),
      '#options' => $candidates,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Propose a search page'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    [$view, $display] = explode(':', (string) $form_state->getValue('view'), 2);
    $form_state->setRedirect('quant_search.create_page', [], ['query' => ['view' => $view, 'display' => $display]]);
  }

}
