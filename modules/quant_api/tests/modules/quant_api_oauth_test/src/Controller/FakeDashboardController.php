<?php

namespace Drupal\quant_api_oauth_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The approval step of the fake dashboard: approves at once.
 */
class FakeDashboardController extends ControllerBase {

  /**
   * Records the authorize request and sends the browser back with a code.
   */
  public function authorize(Request $request) {
    \Drupal::state()->set('quant_api_oauth_test.authorize', $request->query->all());
    if (\Drupal::state()->get('quant_api_oauth_test.deny')) {
      $target = $request->query->get('redirect_uri') . '?error=access_denied&state=' . rawurlencode((string) $request->query->get('state'));
    }
    else {
      $state = (string) (\Drupal::state()->get('quant_api_oauth_test.forge_state') ?: $request->query->get('state'));
      $target = $request->query->get('redirect_uri') . '?code=test-code&state=' . rawurlencode($state);
    }
    return new TrustedRedirectResponse($target);
  }

}
