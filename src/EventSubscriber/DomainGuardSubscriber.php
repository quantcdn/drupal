<?php

namespace Drupal\quant\EventSubscriber;

use Drupal\quant\CliDomainContext;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant\PublishGuard;
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
      // Unpublishing is the one that cannot be walked back. Deleting a node
      // on an unrecognised host would withdraw the matching URL from
      // whichever project the fallback landed on, taking down a live page
      // belonging to another client.
      QuantEvent::UNPUBLISH => ['onOutput', 100],
      // Files and media go to the same project by the same route, so a
      // misdirected run would upload one client's assets into another's.
      QuantFileEvent::OUTPUT => ['onOutput', 100],
    ];

    // Every event the API publisher listens to must appear above. A new one
    // added there and missed here would publish unguarded, and nothing would
    // fail loudly.
    // @see \Drupal\quant\Tests\Unit\DomainGuardSubscriberTest
  }

  /**
   * Stops the push when the serving host has no domain record.
   *
   * @param \Drupal\quant\Event\QuantEvent|\Drupal\quant\Event\QuantRedirectEvent $event
   *   The content or redirect event.
   */
  public function onOutput($event) {
    // Every publish, redirect and unpublish passes through here, which makes
    // it the one place guaranteed to run no matter what triggered the work.
    // Quant's own drush commands negotiate the domain themselves, but a node
    // deleted by drush php:eval, a migration, or any other command does not
    // reach them, and would resolve the base project instead of the domain's.
    // The call is cached per process, so this costs nothing after the first.
    CliDomainContext::initialize();

    // Refuse content that demonstrably belongs to another domain. Stale
    // Domain Access grants let a seed collect every client's pages, and each
    // is then published to the project of whichever domain is being seeded.
    // The routing is correct, so nothing else here notices.
    if ($event instanceof QuantEvent && PublishGuard::belongsToAnotherDomain($event->getEntity(), $owners)) {
      \Drupal::logger('quant')->error('Refused to publish @path: Domain Access assigns it to @owners, not to the domain being published. Rebuild node permissions at /admin/reports/status, then seed each domain again.', [
        '@path' => self::describe($event),
        '@owners' => $owners,
      ]);

      $event->stopPropagation();

      return;
    }

    if (!PublishGuard::refuses($host, $this->requestStack)) {
      return;
    }

    // Stopping here also skips the render and search work the later
    // subscribers would do, which the client-level backstop cannot.
    PublishGuard::logRefusal('to publish ' . self::describe($event), $host);

    $event->stopPropagation();
  }

  /**
   * Names the thing being published, for the log message.
   *
   * @param object $event
   *   A content, file or redirect event.
   *
   * @return string
   *   The path or url the event concerns.
   */
  protected static function describe($event) : string {
    if ($event instanceof QuantEvent) {
      return $event->getLocation();
    }

    if ($event instanceof QuantFileEvent) {
      return $event->getUrl();
    }

    return $event->getSourceUrl();
  }

}
