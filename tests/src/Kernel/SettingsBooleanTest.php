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

    // The token settings too.
    $this->container->get('config.storage')->write('quant.token_settings', [
      'disable' => 0,
      'strict' => 1,
      'secret' => 's',
      'timeout' => '+1 minute',
    ]);
    $this->container->get('config.factory')->reset('quant.token_settings');
    quant_update_9009();
    $this->assertFalse($this->config('quant.token_settings')->get('disable'));
    $this->assertTrue($this->config('quant.token_settings')->get('strict'));
  }

  /**
   * The update drops the unticked keys of the Quant metadata Views option.
   */
  public function testUpdateFiltersTheViewsOption(): void {
    $name = 'views.view.quant_metadata_node';
    $path = 'display.default.display_options.fields.quant_metadata.quant_metadata';
    $file = $this->root . '/' . \Drupal::service('extension.list.module')->getPath('quant') . "/config/install/$name.yml";
    $view = Yaml::decode(file_get_contents($file));
    $option = &$view['display']['default']['display_options']['fields']['quant_metadata']['quant_metadata'];
    $option['md5'] = 0;
    $option['seq_num'] = 0;
    $this->container->get('config.storage')->write($name, $view);
    $this->container->get('config.factory')->reset($name);

    \Drupal::moduleHandler()->loadInclude('quant', 'install');
    quant_update_9010();

    $kept = $this->config($name)->get($path);
    $this->assertArrayNotHasKey('md5', $kept);
    $this->assertArrayNotHasKey('seq_num', $kept);
    $this->assertSame('url', $kept['url']);
  }

}
