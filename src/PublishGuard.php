<?php

namespace Drupal\quant;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Decides whether the current context may write to a Quant project.
 *
 * The Domain module falls back to the default domain when the request host
 * matches no domain record. On a site publishing many clients from one Drupal
 * instance, that sends whatever is being written to the default client's
 * project instead: a page appears on the wrong site, or worse, one is
 * withdrawn from it.
 *
 * The decision lives here rather than in the event subscriber because not
 * everything that writes goes through an event. Search records, facets and
 * index clearing call the API client directly, and guarding only the event
 * path left those open. Both the subscriber and the client consult this, so a
 * new write method is covered without anyone remembering to add a check.
 *
 * @see \Drupal\quant\EventSubscriber\DomainGuardSubscriber
 * @see \Drupal\quant_api\Client\QuantClient
 *
 * @ingroup quant
 */
class PublishGuard {

  /**
   * Determines whether writing must be refused in the current context.
   *
   * @param string|null $host
   *   Set to the offending host when the check fails.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, where the caller has one injected. Falls back to the
   *   container otherwise.
   *
   * @return bool
   *   TRUE when nothing may be written.
   */
  public static function refuses(&$host = NULL, ?RequestStack $requestStack = NULL) : bool {
    $host = NULL;

    // No container means no domain module, and nothing to get wrong. This
    // also keeps unit tests that never build one from fatalling here.
    if (!\Drupal::hasContainer()) {
      return FALSE;
    }

    if (!\Drupal::hasService('module_handler') || !\Drupal::moduleHandler()->moduleExists('domain')) {
      return FALSE;
    }

    if (!$requestStack) {
      if (!\Drupal::hasService('request_stack')) {
        return FALSE;
      }
      $requestStack = \Drupal::service('request_stack');
    }

    if (!\Drupal::hasService('entity_type.manager')) {
      return FALSE;
    }

    $request = $requestStack->getCurrentRequest();

    if (!$request) {
      return FALSE;
    }

    $host = $request->getHttpHost();
    $storage = \Drupal::entityTypeManager()->getStorage('domain');

    // With a single domain there is only one project to write to, so the
    // fallback cannot reach anywhere unexpected. Only a genuine multi-domain
    // site can lose a page to another site's project.
    if (count($storage->loadMultiple()) < 2) {
      return FALSE;
    }

    return empty($storage->loadByHostname($host));
  }

  /**
   * Logs a refusal, naming the host and the project that would have received.
   *
   * @param string $what
   *   The path, url or operation being refused.
   * @param string|null $host
   *   The host that matched no domain.
   */
  public static function logRefusal(string $what, ?string $host) : void {
    \Drupal::logger('quant')->error('Refused @what: the host @host matches no domain, so the Domain module fell back to the default and this would have been written to project @project. Add a domain for @host, or correct the Host header reaching Drupal.', [
      '@what' => $what,
      '@host' => $host ?? 'unknown',
      '@project' => \Drupal::config('quant_api.settings')->get('api_project') ?: 'unknown',
    ]);
  }

}
