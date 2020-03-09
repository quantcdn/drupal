<?php

namespace Drupal\quant;

/**
 * Trait containing methods useful for different static user interfaces.
 *
 * @internal
 */
trait QuantStaticTrait {

  /**
   * Collects warnings to help users correct issues in rendered HTML.
   *
   * @return array
   *   An array of warning messages to display to the user.
   */
  protected function getWarnings() {

    $warnings = [];
    $performance_config = \Drupal::config('system.performance');
    if (!$performance_config->get('css.preprocess') || !$performance_config->get('js.preprocess')) {
      if (!$performance_config->get('css.preprocess') && !$performance_config->get('js.preprocess')) {
        $message = $this->t('CSS and JS preprocessing is disabled.');
      }
      elseif (!$performance_config->get('css.preprocess')) {
        $message = $this->t('CSS preprocessing is disabled.');
      }
      else {
        $message = $this->t('JS preprocessing is disabled.');
      }
      $warnings[] = $message . ' ' . $this->t('This could lead to performance issues. To resolve, visit /admin/config/development/performance.');
    }
    $twig_config = \Drupal::getContainer()->getParameter('twig.config');
    if ($twig_config['debug'] || !$twig_config['cache']) {
      if ($twig_config['debug'] && !$twig_config['cache']) {
        $message = $this->t('Twig debugging is enabled and caching is disabled.');
      }
      elseif ($twig_config['debug']) {
        $message = $this->t('Twig debugging is enabled.');
      }
      else {
        $message = $this->t('Twig caching is disabled.');
      }
      $warnings[] = $message . ' ' . $this->t('This could lead to performance issues. To resolve, edit the "twig.config" parameter in the "sites/*/services.yml" file, then rebuild cache.');
    }
    return $warnings;
  }

}
