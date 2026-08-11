<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Refuses to publish a page served from an unrecognised host.
 *
 * The Domain module falls back to the default domain when the request host
 * matches no domain record. That is a reasonable default for rendering, but
 * on a site publishing many clients from one Drupal instance it means the
 * content of whichever client is being served reaches the default client's
 * Quant project instead. Nothing reports it: the page renders, the save
 * succeeds, and the wrong site changes.
 *
 * A host with no domain record is always a configuration error — a domain
 * not yet added, an alias nobody registered, or a proxy forwarding the wrong
 * Host header. Publishing on a guess is worse than not publishing, so this
 * stops the push and says why.
 *
 * This applies to the command line too. A --uri that names no configured
 * domain, or a cron run given no --uri at all, falls back the same way and
 * publishes an entire site into the default domain's project.
 *
 * @ingroup quant
 */
class DomainGuardSubscriber implements EventSubscriberInterface {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs the subscriber.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of quant_search (1) and the API publisher (0), so that stopping
    // propagation prevents the push entirely.
    return [QuantEvent::OUTPUT => ['onOutput', 100]];
  }

  /**
   * Stops the push when the serving host has no domain record.
   *
   * @param \Drupal\quant\Event\QuantEvent $event
   *   The event.
   */
  public function onOutput(QuantEvent $event) {
    if (!$this->hostIsUnknown($host)) {
      return;
    }

    \Drupal::logger('quant')->error('Refused to publish @path: the host @host matches no domain, so the Domain module fell back to the default and this content would be published to project @project. Add a domain for @host, or correct the Host header reaching Drupal.', [
      '@path' => $event->getLocation(),
      '@host' => $host,
      '@project' => \Drupal::config('quant_api.settings')->get('api_project') ?: 'unknown',
    ]);

    $event->stopPropagation();
  }

  /**
   * Determines whether the serving host has no domain record.
   *
   * @param string|null $host
   *   Set to the offending host when the check fails.
   *
   * @return bool
   *   TRUE when the push must be stopped.
   */
  protected function hostIsUnknown(&$host = NULL) : bool {
    $host = NULL;

    $moduleHandler = \Drupal::moduleHandler();

    if (!$moduleHandler->moduleExists('domain')) {
      return FALSE;
    }

    $request = $this->requestStack->getCurrentRequest();

    if (!$request) {
      return FALSE;
    }

    $host = $request->getHttpHost();
    $storage = \Drupal::entityTypeManager()->getStorage('domain');

    // No domains configured at all means the site is not using per-domain
    // projects, so there is nothing to get wrong.
    if (empty($storage->loadMultiple())) {
      return FALSE;
    }

    return empty($storage->loadByHostname($host));
  }

}
