<?php

namespace Drupal\quant_webform\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant_webform\QuantFormsMapper;
use Drupal\quant_webform\QuantFormsRegistry;
use Drupal\webform\WebformInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the Quant Forms config to the push of a page that shows a Webform.
 *
 * The export render marks each Webform form with data-quant-webform. The
 * config goes inline in the content push, so the page and its form config
 * are published together.
 */
class QuantFormsSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QuantFormsMapper $mapper,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected QuantFormsRegistry $registry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Before the API subscriber (priority 0) sends the payload.
    return [QuantEvent::OUTPUT => ['onOutput', 2]];
  }

  /**
   * Adds form_enabled and form_config to the event metadata.
   *
   * A page registered before that no longer has a registrable form (removed,
   * opted out, closed, unsupported) gets form_enabled FALSE, so it stops
   * taking submissions. A page this site never registered is left alone.
   */
  public function onOutput(QuantEvent $event): void {
    $path = $event->getLocation();
    $webform = $this->registrableWebform($event, $path);
    $meta = $event->getMetadata();
    if ($webform !== NULL) {
      $report = $this->mapper->map($webform, $path);
      $meta['form_enabled'] = TRUE;
      $meta['form_config'] = $report['config'];
      $this->registry->record($path, $webform->id());
      $event->setMetadata($meta);
      return;
    }
    if ($this->registry->webformAt($path) !== NULL) {
      $meta['form_enabled'] = FALSE;
      $this->registry->forget($path);
      $event->setMetadata($meta);
      $this->loggerFactory->get('quant_webform')->notice('Quant Forms: @url no longer has a registered Webform, so Quant Forms is turned off there.', ['@url' => $path]);
    }
  }

  /**
   * The first opted-in, open and supported Webform that posts to the page.
   */
  protected function registrableWebform(QuantEvent $event, string $path): ?WebformInterface {
    $logger = $this->loggerFactory->get('quant_webform');
    $storage = $this->entityTypeManager->getStorage('webform');
    $found = [];
    foreach (self::webformIds((string) $event->getContents(), $path) as $id) {
      $webform = $storage->load($id);
      if ($webform && QuantFormsRegistry::isOptedIn($webform) && $webform->isOpen()) {
        $found[] = $webform;
      }
    }
    if (count($found) > 1) {
      $logger->warning('Quant Forms: @url shows several Webforms that post to it (@ids). Quant takes one form per page; only @first is registered.', [
        '@url' => $path,
        '@ids' => implode(', ', array_map(fn ($w) => $w->id(), $found)),
        '@first' => $found[0]->id(),
      ]);
    }
    $webform = $found[0] ?? NULL;
    if ($webform === NULL) {
      return NULL;
    }
    $report = $this->mapper->map($webform, $path);
    if (!$report['supported']) {
      $logger->warning('Quant Forms: the Webform @id on @url is not registered: @reasons', [
        '@id' => $webform->id(),
        '@url' => $path,
        '@reasons' => implode(' ', array_map('strval', $report['blocking'])),
      ]);
      return NULL;
    }
    return $webform;
  }

  /**
   * The Webforms that post to this page, in page order.
   *
   * A form marked with data-quant-target posts to that path, so it belongs to
   * the page at that path only. A form without a target posts to the page
   * that shows it.
   *
   * @param string $html
   *   The rendered page.
   * @param string|null $location
   *   The page path. NULL takes every marked form (no target check).
   */
  public static function webformIds(string $html, ?string $location = NULL): array {
    preg_match_all('/<form\b[^>]*>/i', $html, $tags);
    $ids = [];
    foreach ($tags[0] as $tag) {
      if (!preg_match('/data-quant-webform="([a-z0-9_]+)"/', $tag, $id)) {
        continue;
      }
      $target = preg_match('/data-quant-target="([^"]*)"/', $tag, $t) ? html_entity_decode($t[1]) : NULL;
      if ($location === NULL || $target === NULL || rtrim($target, '/') === rtrim($location, '/')) {
        $ids[] = $id[1];
      }
    }
    return array_values(array_unique($ids));
  }

}
