<?php

namespace Drupal\Tests\quant_search\Traits;

/**
 * Waits for the Quant API calls that a page request makes after its response.
 *
 * A search page publishes in a shutdown function, as a node save does. The
 * test web server sends the response before the shutdown functions run, so
 * a test that reads the fake API calls right after a form submit can read
 * them too early.
 */
trait WaitsForQuantCallsTrait {

  /**
   * Waits until the fake API has recorded a call that matches.
   *
   * @param callable $matches
   *   Gets one recorded call and returns whether it is the one to wait for.
   * @param int $seconds
   *   How long to wait.
   */
  protected function waitForQuantCall(callable $matches, int $seconds = 60): void {
    $end = microtime(TRUE) + $seconds;
    do {
      \Drupal::state()->resetCache();
      if (array_filter(\Drupal::state()->get('quant_search_test.calls', []), $matches)) {
        // Long enough for a late second publish to arrive, so that a test
        // that expects one publish sees a duplicate.
        sleep(3);
        \Drupal::state()->resetCache();
        return;
      }
      // Not more often: on SQLite each read delays the site's writes.
      sleep(1);
    } while (microtime(TRUE) < $end);
    $this->fail('The Quant API call did not come.');
  }

  /**
   * Waits until the fake API has recorded a publish of this path.
   */
  protected function waitForPublish(string $url): void {
    $this->waitForQuantCall(fn ($call) => $call['method'] === 'POST' && $call['path'] === '/v1' && ($call['body']['url'] ?? '') === $url);
  }

}
