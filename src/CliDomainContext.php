<?php

namespace Drupal\quant;

/**
 * Resolves per-domain configuration overrides in CLI contexts.
 *
 * Sites that serve several domains from one Drupal instance give each domain
 * its own Quant project, using the Domain module's config overrides. The
 * Domain module populates its negotiation context from a kernel.request
 * subscriber. Drush never dispatches that event, so the context stays empty
 * under CLI even when --uri names a valid domain, and the config factory
 * resolves base values instead of the domain's.
 *
 * Left unhandled this fails silently: every domain's content is published to
 * whichever project the base configuration names.
 *
 * @ingroup quant
 */
class CliDomainContext {

  /**
   * Negotiates the active domain so configuration overrides apply.
   *
   * Safe to call when the Domain module is absent. In that case it is a no-op
   * and base configuration continues to apply.
   *
   * @return string|null
   *   The active domain id, or NULL when no domain was negotiated.
   */
  public static function initialize() : ?string {
    $moduleHandler = \Drupal::moduleHandler();

    if (!$moduleHandler->moduleExists('domain')) {
      return NULL;
    }

    if (!\Drupal::hasService('domain.negotiator')) {
      return NULL;
    }

    $domain = \Drupal::service('domain.negotiator')->getActiveDomain();

    if (empty($domain)) {
      return NULL;
    }

    // Any config object built before negotiation was cached without the
    // override applied. Drop the static cache so the overridden values
    // resolve on the next read.
    \Drupal::configFactory()->reset();

    return $domain->id();
  }

  /**
   * Returns the Quant project the current context resolves to.
   *
   * @return string|null
   *   The project machine name, or NULL when none is configured.
   */
  public static function getActiveProject() : ?string {
    return \Drupal::config('quant_api.settings')->get('api_project') ?: NULL;
  }

}
