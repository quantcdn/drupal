<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantRedirectEvent;
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
 * This only engages where more than one domain is configured, because that is
 * the only arrangement in which the fallback can reach a different site's
 * project. A site with one domain, or none, has a single destination and
 * publishes as it always has, whether or not a --uri was given. That matters
 * on the command line especially, where plenty of sites run cron and seeds
 * without one.
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
    return [
      // Ahead of quant_search (1) and the API publisher (0), so that stopping
      // propagation prevents the push entirely.
      QuantEvent::OUTPUT => ['onOutput', 100],
      // Redirects reach the same project by the same route, so guarding only
      // content would still let a misdirected run rewrite another client's
      // redirect map. The publisher listens at -999.
      QuantRedirectEvent::UPDATE => ['onOutput', 100],
    ];
  }

  /**
   * Stops the push when the serving host has no domain record.
   *
   * @param \Drupal\quant\Event\QuantEvent|\Drupal\quant\Event\QuantRedirectEvent $event
   *   The content or redirect event.
   */
  public function onOutput($event) {
    if (!$this->hostIsUnknown($host)) {
      return;
    }

    \Drupal::logger('quant')->error('Refused to publish @path: the host @host matches no domain, so the Domain module fell back to the default and this content would be published to project @project. Add a domain for @host, or correct the Host header reaching Drupal.', [
      '@path' => $event instanceof QuantEvent ? $event->getLocation() : $event->getSourceUrl(),
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

    // With a single domain there is only one project to publish to, so the
    // fallback cannot send content anywhere unexpected. Only a genuine
    // multi-domain site can lose a page to another site's project.
    if (count($storage->loadMultiple()) < 2) {
      return FALSE;
    }

    return empty($storage->loadByHostname($host));
  }

}
