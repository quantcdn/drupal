<?php

namespace Drupal\quant;

use Drupal\Core\Entity\FieldableEntityInterface;
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

  /**
   * Determines whether node grants are stale on a multi-domain site.
   *
   * Domain Access decides which domain serves which node, through node
   * grants.
   * Enabling it sets Drupal's needs-rebuild flag, and until someone rebuilds,
   * every node is visible on every domain. A seed then collects all of it and
   * publishes one client's pages into another client's project — correctly
   * routed, but scoped wrongly, so nothing here notices.
   *
   * Reported rather than enforced. The flag means grants may be stale, not
   * that they are, and it stays set until an administrator acts, which on a
   * large site can be a while. Refusing to publish for that whole window
   * would be worse than saying so loudly.
   *
   * @return bool
   *   TRUE when a rebuild is outstanding and it could mis-scope content.
   */
  public static function nodeGrantsAreStale() : bool {
    if (!\Drupal::hasContainer() || !\Drupal::hasService('module_handler')) {
      return FALSE;
    }

    $moduleHandler = \Drupal::moduleHandler();

    if (!$moduleHandler->moduleExists('domain_access') || !$moduleHandler->moduleExists('node')) {
      return FALSE;
    }

    // With one domain there is nowhere else for content to go.
    if (!\Drupal::hasService('entity_type.manager')) {
      return FALSE;
    }

    $domains = \Drupal::entityTypeManager()->getStorage('domain')->loadMultiple();

    if (count($domains) < 2) {
      return FALSE;
    }

    $moduleHandler->loadInclude('node', 'module');

    return function_exists('node_access_needs_rebuild') && node_access_needs_rebuild();
  }

  /**
   * Determines whether a node belongs to a domain other than the active one.
   *
   * This is proof rather than suspicion. Domain Access records which domains
   * serve a node, so a node that names other domains and not this one is
   * being published to the wrong project, whatever the reason. Stale grants
   * are the usual cause, but a bypassed access check or a query that forgets
   * to apply one would land here too.
   *
   * Silent on anything it cannot be certain about: no Domain Access, fewer
   * than two domains, no active domain, an entity with no assignment, or one
   * marked for all affiliates. Publishing is only refused on evidence.
   *
   * @param mixed $entity
   *   The entity being published, if the event carries one.
   * @param string|null $owners
   *   Set to the domains that do own it, for the log message.
   *
   * @return bool
   *   TRUE when the entity demonstrably belongs elsewhere.
   */
  public static function belongsToAnotherDomain($entity, &$owners = NULL) : bool {
    $owners = NULL;

    if (!$entity instanceof FieldableEntityInterface) {
      return FALSE;
    }

    if (!$entity->hasField('field_domain_access')) {
      return FALSE;
    }

    // Content marked for every affiliate is legitimately on all domains.
    if ($entity->hasField('field_domain_all_affiliates') && !empty($entity->get('field_domain_all_affiliates')->value)) {
      return FALSE;
    }

    $assigned = array_column($entity->get('field_domain_access')->getValue(), 'target_id');

    // No assignment means no claim about where it belongs.
    if (empty($assigned)) {
      return FALSE;
    }

    $active = static::activeDomainId();

    if ($active === NULL) {
      return FALSE;
    }

    if (in_array($active, $assigned, TRUE)) {
      return FALSE;
    }

    $owners = implode(', ', $assigned);

    return TRUE;
  }

  /**
   * Returns the active domain id, where the site has more than one.
   *
   * @return string|null
   *   The domain id, or NULL when nothing can be concluded from it.
   */
  protected static function activeDomainId() : ?string {
    if (!\Drupal::hasContainer() || !\Drupal::hasService('module_handler')) {
      return NULL;
    }

    $moduleHandler = \Drupal::moduleHandler();

    if (!$moduleHandler->moduleExists('domain_access') || !\Drupal::hasService('domain.negotiator')) {
      return NULL;
    }

    $storage = \Drupal::entityTypeManager()->getStorage('domain');

    // One domain has nowhere else to send anything.
    if (count($storage->loadMultiple()) < 2) {
      return NULL;
    }

    $domain = \Drupal::service('domain.negotiator')->getActiveDomain();

    return $domain ? $domain->id() : NULL;
  }

}
