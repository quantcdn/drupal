<?php

namespace Drupal\Tests\quant\Unit;

use Drupal\quant\Utility;
use Drupal\Tests\UnitTestCase;

/**
 * Ensures malformed paths cannot reach the API.
 *
 * Paths are assembled from language prefixes, base paths and aliases, any of
 * which can be empty. //fr/node/1 is a different resource from /fr/node/1, so
 * a stray slash publishes a duplicate nobody asked for.
 *
 * @coversDefaultClass \Drupal\quant\Utility
 *
 * @group quant
 */
class UtilityNormalizePathTest extends UnitTestCase {

  /**
   * Paths and what they should collapse to.
   *
   * @return array
   *   Test cases.
   */
  public static function pathProvider() : array {
    return [
      'already correct' => ['/fr/node/1', '/fr/node/1'],
      'doubled prefix slash' => ['//fr/node/1', '/fr/node/1'],
      'doubled root slash' => ['//node/1', '/node/1'],
      'tripled' => ['///node/1', '/node/1'],
      'interior double' => ['/fr//node/1', '/fr/node/1'],
      'trailing double' => ['/fr/node//', '/fr/node/'],
      'root stays root' => ['/', '/'],
      'empty becomes root' => ['', '/'],
      'query string kept' => ['//fr/search?page=2', '/fr/search?page=2'],
      // An oEmbed route carries a whole URL in its query string, and the
      // slashes in that URL are not ours to touch.
      // An absolute url must survive intact: the // in its scheme is not a
      // stray slash, and collapsing it publishes a broken redirect.
      'absolute https untouched' => [
        'https://example.com/a//b',
        'https://example.com/a//b',
      ],
      'absolute http untouched' => [
        'http://other.org/foo',
        'http://other.org/foo',
      ],
      'absolute with query untouched' => [
        'https://example.com/a?b=//c',
        'https://example.com/a?b=//c',
      ],
      // Protocol-relative is indistinguishable from a malformed path, and in
      // this module a bare // is always the latter, so it is collapsed.
      'protocol relative is treated as a path' => [
        '//example.com/x',
        '/example.com/x',
      ],
      'slashes in query untouched' => [
        '//media/oembed?url=https://example.com/a//b',
        '/media/oembed?url=https://example.com/a//b',
      ],
    ];
  }

  /**
   * Repeated slashes collapse, without disturbing the query string.
   *
   * @dataProvider pathProvider
   * @covers ::normalizePath
   */
  public function testNormalizePath(string $input, string $expected) {
    $this->assertEquals($expected, Utility::normalizePath($input));
  }

}
