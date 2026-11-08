<?php

namespace Drupal\quant_webform\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant_webform\QuantFormsMapper;
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
   */
  public function onOutput(QuantEvent $event): void {
    $ids = self::webformIds((string) $event->getContents());
    if ($ids === []) {
      return;
    }
    $logger = $this->loggerFactory->get('quant_webform');
    if (count($ids) > 1) {
      $logger->warning('Quant Forms: @url shows several Webforms (@ids). Quant takes one form per page; only @first is registered.', [
        '@url' => $event->getLocation(),
        '@ids' => implode(', ', $ids),
        '@first' => $ids[0],
      ]);
    }
    $webform = $this->entityTypeManager->getStorage('webform')->load($ids[0]);
    if (!$webform || !$webform->getThirdPartySetting('quant_webform', 'enabled', TRUE) || !$webform->isOpen()) {
      return;
    }
    $report = $this->mapper->map($webform, $event->getLocation());
    if (!$report['supported']) {
      $logger->warning('Quant Forms: the Webform @id on @url is not registered: @reasons', [
        '@id' => $webform->id(),
        '@url' => $event->getLocation(),
        '@reasons' => implode(' ', array_map('strval', $report['blocking'])),
      ]);
      return;
    }
    $meta = $event->getMetadata();
    $meta['form_enabled'] = TRUE;
    $meta['form_config'] = $report['config'];
    $event->setMetadata($meta);
  }

  /**
   * The Webform ids marked in the rendered page, in page order.
   */
  public static function webformIds(string $html): array {
    preg_match_all('/data-quant-webform="([a-z0-9_]+)"/', $html, $matches);
    return array_values(array_unique($matches[1]));
  }

}
