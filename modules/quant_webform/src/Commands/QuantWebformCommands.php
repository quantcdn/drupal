<?php

namespace Drupal\quant_webform\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\quant_webform\QuantFormsMapper;
use Drupal\quant_webform\QuantFormsRegistrar;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Quant Forms.
 */
class QuantWebformCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QuantFormsRegistrar $registrar,
    protected QuantFormsMapper $mapper,
  ) {
    parent::__construct();
  }

  /**
   * Registers Webforms with Quant Forms by publishing their pages now.
   *
   * @param string $id
   *   One Webform id, or empty for every open, registered Webform.
   *
   * @command quant:webform-register
   * @usage drush quant:webform-register
   *   Register every Webform.
   * @usage drush quant:webform-register contact
   *   Register the contact form.
   */
  public function register(string $id = '') {
    $storage = $this->entityTypeManager->getStorage('webform');
    $webforms = $id !== '' ? array_filter([$storage->load($id)]) : $storage->loadMultiple();
    if ($webforms === []) {
      $this->logger()->error(dt('No Webform @id.', ['@id' => $id]));
      return self::EXIT_FAILURE;
    }
    foreach ($webforms as $webform) {
      $this->registerOne($webform);
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Registers one Webform and prints the outcome.
   */
  protected function registerOne($webform): void {
    if (!$webform->isOpen() || !$webform->getThirdPartySetting('quant_webform', 'enabled', TRUE)) {
      $this->io()->writeln(dt('@id: skipped (closed or off for Quant Forms).', ['@id' => $webform->id()]));
      return;
    }
    $pages = $this->registrar->pages($webform);
    $report = $this->mapper->map($webform, $pages[0] ?? '/');
    if (!$report['supported']) {
      $this->io()->writeln(dt('@id: not supported: @why', [
        '@id' => $webform->id(),
        '@why' => implode(' ', array_map('strval', $report['blocking'])),
      ]));
      return;
    }
    $count = $this->registrar->pushNow($webform);
    $this->io()->writeln(dt('@id: @count page(s) published (@pages).', [
      '@id' => $webform->id(),
      '@count' => $count,
      '@pages' => implode(', ', $pages) ?: '-',
    ]));
  }

}
