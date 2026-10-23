<?php

namespace Drupal\Tests\quant_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\quant_api\EndpointValidator;

/**
 * Tests the API endpoint validation.
 *
 * @group quant_api
 */
class EndpointValidatorTest extends UnitTestCase {

  /**
   * Data provider.
   */
  public static function endpoints() {
    return [
      'commercial' => ['https://api.quantcdn.io', NULL],
      'trailing slash' => ['https://api.quantcdn.io/', NULL],
      'gov' => ['https://api.example.gov.test', NULL],
      'http' => ['http://api.quantcdn.io', 'not_https'],
      'no scheme' => ['api.quantcdn.io', 'not_https'],
      'v1 suffix' => ['https://api.quantcdn.io/v1', 'has_v1'],
      'v1 slash' => ['https://api.quantcdn.io/v1/', 'has_v1'],
      'other path' => ['https://api.quantcdn.io/other', 'not_origin'],
      'query' => ['https://api.quantcdn.io?x=1', 'not_origin'],
    ];
  }

  /**
   * @dataProvider endpoints
   */
  public function testValidate($endpoint, $expected) {
    $this->assertSame($expected, EndpointValidator::validate($endpoint));
  }

  /**
   * Trailing slashes are removed.
   */
  public function testNormalise() {
    $this->assertSame('https://api.quantcdn.io', EndpointValidator::normalise(' https://api.quantcdn.io/ '));
  }

}
