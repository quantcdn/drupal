<?php

namespace Drupal\Tests\quant\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The on/off settings are booleans, as their schema says.
 *
 * Drupal 11.1 installs module config as trusted data, without casting it to
 * the schema types, so 0/1 in config/install failed the strict schema check
 * (and stayed integers in the active config of existing sites).
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class SettingsBooleanTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'taxonomy', 'views', 'quant', 'quant_api'];

  /**
   * The settings that the schema types as boolean.
   */
  const KEYS = [
    'quant_enabled',
    'quant_enabled_nodes',
    'quant_enabled_taxonomy',
    'quant_enabled_views',
    'quant_enabled_redirects',
    'ssl_cert_verify',
    'disable_content_drafts',
    'quant_show_page_info_block',
  ];

  /**
   * The shipped defaults are booleans.
   */
  public function testInstallConfigIsBoolean(): void {
    $path = \Drupal::service('extension.list.module')->getPath('quant');
    $data = Yaml::decode(file_get_contents($this->root . '/' . $path . '/config/install/quant.settings.yml'));
    foreach (self::KEYS as $key) {
      $this->assertIsBool($data[$key], "$key is a boolean in config/install.");
    }
  }

  /**
   * The update casts integers that an older install stored.
   */
  public function testUpdateCastsStoredIntegers(): void {
    $values = array_fill_keys(self::KEYS, 1);
    $values['disable_content_drafts'] = 0;
    // Written as given, as the installer of Drupal 11.1 stored it.
    $this->container->get('config.storage')->write('quant.settings', $values + ['xpath_selectors' => '']);
    $this->container->get('config.factory')->reset('quant.settings');
    $this->assertSame(1, $this->config('quant.settings')->get('quant_enabled'));

    \Drupal::moduleHandler()->loadInclude('quant', 'install');
    quant_update_9009();

    $settings = $this->config('quant.settings');
    foreach (self::KEYS as $key) {
      $this->assertIsBool($settings->get($key), "$key is a boolean after the update.");
    }
    $this->assertTrue($settings->get('quant_enabled'));
    $this->assertFalse($settings->get('disable_content_drafts'));
  }

}
