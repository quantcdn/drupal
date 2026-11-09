<?php

namespace Drupal\quant_api;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

/**
 * The pages whose last push to Quant failed, for the whole site.
 *
 * Pushes run at the end of the request, after the response is sent, and
 * from the queue on cron. Nobody sees them fail, so each failure is kept here
 * until a later push of the same page succeeds, and shown to editors on their
 * next page view and in the status report.
 */
class PushFailures {

  /**
   * The state key.
   */
  public const KEY = 'quant_api.push_failures';

  /**
   * The most pages kept; the oldest go first.
   */
  public const MAX = 50;

  /**
   * The cache tag of pages that show the list (or would show it).
   */
  public const CACHE_TAG = 'quant_api_push_failures';

  public function __construct(
    protected StateInterface $state,
    protected TimeInterface $time,
    protected CacheTagsInvalidatorInterface $invalidator,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Records that a push of a page failed.
   *
   * @param string $url
   *   The page path.
   * @param string $operation
   *   What was sent: publish, redirect, unpublish or file.
   * @param \Throwable $error
   *   The failure. Only a short reason is kept, never its message, which can
   *   name the endpoint URL with credentials.
   */
  public function record(string $url, string $operation, \Throwable $error): void {
    $this->update(function (array $all) use ($url, $operation, $error) {
      unset($all[$url]);
      $all[$url] = [
        'operation' => $operation,
        'reason' => self::reason($error),
        'at' => $this->time->getRequestTime(),
      ];
      return array_slice($all, -self::MAX, NULL, TRUE);
    });
  }

  /**
   * Forgets a page after a push of it succeeded.
   */
  public function clear(string $url): void {
    // Most pushes succeed: read without the lock first.
    if (!isset($this->all()[$url])) {
      return;
    }
    $this->update(function (array $all) use ($url) {
      unset($all[$url]);
      return $all;
    });
  }

  /**
   * Changes the list under a lock, so queue workers do not lose entries.
   *
   * @param callable $change
   *   Gets the list and returns the new list.
   */
  protected function update(callable $change): void {
    $locked = $this->lock->acquire(self::KEY, 5.0) || (!$this->lock->wait(self::KEY, 5) && $this->lock->acquire(self::KEY, 5.0));
    try {
      $this->state->resetCache();
      $before = $this->all();
      $after = $change($before);
      if ($after !== $before) {
        $this->state->set(self::KEY, $after);
        $this->invalidator->invalidateTags([self::CACHE_TAG]);
      }
    }
    finally {
      if ($locked) {
        $this->lock->release(self::KEY);
      }
    }
  }

  /**
   * The failed pages, oldest first, keyed by path.
   *
   * @return array<string, array{operation: string, reason: string, at: int}>
   *   The failures.
   */
  public function all(): array {
    return (array) $this->state->get(self::KEY, []);
  }

  /**
   * A short reason that is safe to show to an editor.
   */
  protected static function reason(\Throwable $error): string {
    if ($error instanceof ConnectException) {
      return 'Quant could not be reached';
    }
    if ($error instanceof RequestException && $error->getResponse()) {
      return 'Quant answered ' . $error->getResponse()->getStatusCode();
    }
    return 'the request failed';
  }

}
