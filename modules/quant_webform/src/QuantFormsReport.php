<?php

namespace Drupal\quant_webform;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\webform\WebformInterface;

/**
 * Shows what of a Webform works on the static site.
 */
class QuantFormsReport {

  use StringTranslationTrait;

  public function __construct(
    protected QuantFormsMapper $mapper,
    protected QuantFormsRegistrar $registrar,
  ) {}

  /**
   * The report of one Webform as a render array.
   */
  public function build(WebformInterface $webform): array {
    $pages = $this->registrar->pages($webform);
    $report = $this->mapper->map($webform, $pages[0] ?? '/');
    $build = [
      '#type' => 'item',
      '#title' => $report['supported'] ? $this->t('This form works on the static site.') : $this->t('This form does not work on the static site yet.'),
    ];
    if ($report['blocking'] !== []) {
      $build['blocking'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Change this first'),
        '#items' => $report['blocking'],
      ];
    }
    if ($report['notes'] !== []) {
      $build['notes'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('What works differently'),
        '#items' => $report['notes'],
      ];
    }
    if (!$webform->getSetting('page')) {
      $build['placement'] = [
        '#markup' => '<p>' . $this->t('This form has no page of its own. A page that shows it in a block, a paragraph or Layout Builder is registered only when that page is published, and saving the form re-publishes only nodes with a Webform field and pages already registered. Give the form its own page to have one address that always takes its submissions.') . '</p>',
      ];
    }
    $build['pages'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Pages that take its submissions'),
      '#items' => $pages ?: [$this->t('None. Give the form a page, or add it to a published node with a Webform field.')],
    ];
    return $build;
  }

}
