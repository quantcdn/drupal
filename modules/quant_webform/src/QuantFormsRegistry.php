<?php

namespace Drupal\quant_webform;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\webform\WebformInterface;

/**
 * Which pages this site registered with Quant Forms, and for which Webform.
 *
 * Quant Forms settings made by hand in the dashboard are never touched: the
 * module turns Quant Forms off only on a page it registered itself. The map
 * also finds pages that saving a Webform cannot find (a block, a paragraph,
 * a Layout Builder placement), because a page is recorded when it publishes.
 */
class QuantFormsRegistry {

  /**
   * The map: page path => Webform id.
   */
  protected KeyValueStoreInterface $pages;

  /**
   * The last known open state of scheduled Webforms: id => bool.
   */
  protected KeyValueStoreInterface $openState;

  public function __construct(KeyValueFactoryInterface $key_value) {
    $this->pages = $key_value->get('quant_webform.registered');
    $this->openState = $key_value->get('quant_webform.open_state');
  }

  /**
   * Whether the editor opted this Webform in to Quant Forms (default off).
   *
   * Off by default, so an upgrade never replaces settings that a customer
   * made by hand in the dashboard.
   */
  public static function isOptedIn(WebformInterface $webform): bool {
    return (bool) $webform->getThirdPartySetting('quant_webform', 'enabled', FALSE);
  }

  /**
   * Records that a page takes a Webform's submissions.
   */
  public function record(string $path, string $webform_id): void {
    $this->pages->set($path, $webform_id);
  }

  /**
   * The Webform a page was registered for, or NULL.
   */
  public function webformAt(string $path): ?string {
    $id = $this->pages->get($path);
    return is_string($id) ? $id : NULL;
  }

  /**
   * The pages registered for a Webform.
   *
   * @return string[]
   *   Page paths.
   */
  public function pagesOf(string $webform_id): array {
    return array_keys(array_filter($this->pages->getAll(), fn ($id) => $id === $webform_id));
  }

  /**
   * Forgets a page.
   */
  public function forget(string $path): void {
    $this->pages->delete($path);
  }

  /**
   * Whether a scheduled Webform opened or closed since the last check.
   *
   * @return bool|null
   *   The new open state when it changed, NULL when it did not (or on the
   *   first check).
   */
  public function openStateChange(WebformInterface $webform): ?bool {
    $now = $webform->isOpen();
    $before = $this->openState->get($webform->id());
    $this->openState->set($webform->id(), $now);
    return ($before === NULL || $before === $now) ? NULL : $now;
  }

  /**
   * Forgets everything (uninstall).
   */
  public function clear(): void {
    $this->pages->deleteAll();
    $this->openState->deleteAll();
  }

}
