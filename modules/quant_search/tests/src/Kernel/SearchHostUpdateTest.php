<?php

namespace Drupal\Tests\quant_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Update 8105 stops storing the default search host and keeps a custom one.
 *
 * @group quant_search
 */
#[RunTestsInSeparateProcesses]
class SearchHostUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'node', 'taxonomy', 'views', 'quant_api', 'quant', 'quant_search'];

  /**
   * The old stored default is cleared; a host set by the site stays.
   */
  public function testUpdateClearsOnlyTheOldDefault() : void {
    $this->installConfig(['quant_search']);
    $this->assertSame('', $this->config('quant_search.settings')->get('typesense_endpoint'));

    \Drupal::moduleHandler()->loadInclude('quant_search', 'install');
    $this->config('quant_search.settings')->set('typesense_endpoint', 'https://search.quantgov.cloud')->save();
    quant_search_update_8105();
    $this->assertSame('', $this->config('quant_search.settings')->get('typesense_endpoint'));

    $this->config('quant_search.settings')->set('typesense_endpoint', 'https://search.example.test')->save();
    quant_search_update_8105();
    $this->assertSame('https://search.example.test', $this->config('quant_search.settings')->get('typesense_endpoint'));
  }

}
