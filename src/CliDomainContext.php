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
   * The domain id resolved by the first initialize() call.
   *
   * @var string|null
   */
  protected static $domainId = NULL;

  /**
   * Whether initialize() has already run in this process.
   *
   * @var bool
   */
  protected static $initialized = FALSE;

  /**
   * Negotiates the active domain so configuration overrides apply.
   *
   * Safe to call when the Domain module is absent. In that case it is a no-op
   * and base configuration continues to apply.
   *
   * The result is cached for the life of the process. A single CLI process
   * serves one domain, and resetting the config factory repeatedly would
   * discard every cached config object for no gain, so callers in loops and
   * batch callbacks can call this freely.
   *
   * @return string|null
   *   The active domain id, or NULL when no domain was negotiated.
   */
  public static function initialize() : ?string {
    if (static::$initialized) {
      return static::$domainId;
    }

    static::$initialized = TRUE;

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

    static::$domainId = $domain->id();

    // An HTTP request negotiates its domain from kernel.request, before any
    // Quant code runs, so the config factory already holds the overridden
    // values. Only CLI reaches this point with stale objects cached, and
    // dropping them in a web request would discard the whole config cache
    // for no benefit.
    if (PHP_SAPI === 'cli') {
      \Drupal::configFactory()->reset();
    }

    return static::$domainId;
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

  /**
   * Forgets the negotiated domain.
   *
   * Only needed by tests, which exercise several domains in one process.
   */
  public static function reset() : void {
    static::$initialized = FALSE;
    static::$domainId = NULL;
  }

}
