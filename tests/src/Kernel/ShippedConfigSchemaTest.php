<?php

namespace Drupal\Tests\quant\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Every shipped config file of quant and its submodules matches its schema.
 *
 * The files are checked as they are on disk, before any cast, so the test
 * fails on every core version for a value that Drupal 11.1 would install
 * as is (for example 0/1 for a boolean). One run lists every mismatch.
 *
 * @group quant
 */
#[RunTestsInSeparateProcesses]
class ShippedConfigSchemaTest extends KernelTestBase {

  use SchemaCheckTrait;

  /**
   * Quant, the submodules that CI can install, and their dependencies.
   *
   * Not here: quant_tome (needs tome; it ships no config).
   *
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'link',
    'node',
    'taxonomy',
    'views',
    'path_alias',
    'token',
    'purge',
    'webform',
    'quant',
    'quant_api',
    'quant_cron',
    'quant_purger',
    'quant_search',
    'quant_sitemap',
    'quant_webform',
  ];

  /**
   * Checks every config/install and config/optional file against its schema.
   */
  public function testShippedConfigMatchesSchema(): void {
    $root = $this->root . '/' . \Drupal::service('extension.list.module')->getPath('quant');
    $files = glob($root . '{,/modules/*}/config/{install,optional}/*.yml', GLOB_BRACE);
    $this->assertNotEmpty($files);

    $typed = \Drupal::service('config.typed');
    $handler = \Drupal::moduleHandler();
    $errors = [];
    $checked = 0;
    foreach ($files as $file) {
      $name = basename($file, '.yml');
      $data = Yaml::decode(file_get_contents($file));
      // Optional config whose modules are not here would not be installed.
      $needs = $data['dependencies']['module'] ?? [];
      if (array_filter($needs, fn ($module) => !$handler->moduleExists($module))) {
        continue;
      }
      $result = $this->checkConfigSchema($typed, $name, $data);
      $checked++;
      if ($result === FALSE) {
        $errors[] = "$name: no schema";
      }
      elseif (is_array($result)) {
        foreach ($result as $key => $error) {
          $errors[] = "$key: $error";
        }
      }
    }
    $this->assertGreaterThanOrEqual(9, $checked, 'The shipped config files were checked.');
    $this->assertSame([], $errors, "Shipped config that does not match its schema:\n" . implode("\n", $errors));
  }

}
