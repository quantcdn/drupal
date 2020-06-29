<?php

namespace Drupal\quant_api\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant_api\Client\QuantClientInterface;
use Exception;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Exception\InvalidPayload;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;

/**
 * Integrate with the QuantAPI to store static assets.
 */
class QuantApi implements EventSubscriberInterface {

  /**
   * The HTTP client to make API requests.
   *
   * @var \Drupal\quant_api\Client\QuantClientInterface
   */
  protected $client;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The event dispatcher.
   *
   * @var \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher
   */
  protected $eventDispatcher;

  /**
   * QuantAPI event subcsriber.
   *
   * Listens to Quant events and triggers requests to the configured
   * API endpoint for different operations.
   *
   * @param \Drupal\quant_api\Client\QuantClientInterface $client
   *   The Drupal HTTP Client to make requests.
   */
  public function __construct(QuantClientInterface $client, LoggerChannelFactoryInterface $logger_factory, ContainerAwareEventDispatcher $event_dispatcher) {
    $this->client = $client;
    $this->logger = $logger_factory->get('quant_api');
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[QuantEvent::OUTPUT][] = ['onOutput'];
    $events[QuantFileEvent::OUTPUT][] = ['onMedia'];
    $events[QuantRedirectEvent::UPDATE][] = ['onRedirect'];
    $events[QuantEvent::UNPUBLISH][] = ['onUnpublish'];
    return $events;
  }

  /**
   * Trigger an API redirect update with event data.
   *
   * @param Drupal\quant\Event\QuantRedirectEvent $event
   *   The redirect event.
   */
  public function onRedirect(QuantRedirectEvent $event) {
    $source = $event->getSourceUrl();
    $dest = $event->getDestinationUrl();
    $statusCode = $event->getStatusCode();

    $data = [
      'url' => $source,
      'redirect_url' => $dest,
      'redirect_http_code' => (int)$statusCode,
      'published' => TRUE,
    ];

    try {
      $res = $this->client->sendRedirect($data);
    }
    catch (Exception $error) {
      $this->logger->error($error->getMessage());
    }

    return $res;
  }


  /**
   * Trigger an API request with the event data.
   *
   * @param Drupal\quant\Event\QuantEvent $event
   *   The event.
   */
  public function onOutput(QuantEvent $event) {

    $path = $event->getLocation();
    $content = $event->getContents();
    $meta = $event->getMetadata();

    $data = [
      'content' => $content,
      'url' => $path,
      'published' => $meta['published'],
      'transitions' => $meta['transitions'],
      'info' => $meta['info'],
    ];

    if (!empty($rid = $event->getRevisionId())) {
      $data['revision'] = $rid;
    }

    try {
      $res = $this->client->send($data);
    }
    catch (Exception $error) {
      $this->logger->error($error->getMessage());
      return FALSE;
    }

    $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);

    foreach ($media as $item) {
      // @todo: Determine local vs. remote.
      // @todo: Configurable to disallow remote files.
      // @todo: Strip base domain.
      $file = urldecode($item['path']);

      if (isset($item['existing_md5'])) {
        if (file_exists(DRUPAL_ROOT . $file) && md5_file(DRUPAL_ROOT . $file) == $item['existing_md5']) {
          continue;
        }
      }

      // Ignore anything that isn't relative for now.
      if (substr($file, 0, 1) != "/") {
        continue;
      }

      // Strip query params.
      $file = strtok($file, '?');

      if (file_exists(DRUPAL_ROOT . $file)) {
        $this->eventDispatcher->dispatch(QuantFileEvent::OUTPUT, new QuantFileEvent(DRUPAL_ROOT . $file, $file));
      }
    }

    // @todo: Report on forms that need proxying (attachments.forms).
  }

  /**
   * Trigger an API push with event data for file.
   *
   * @param Drupal\quant\Event\QuantFileEvent $event
   *   The file event.
   */
  public function onMedia(QuantFileEvent $event) {
    $file = $event->getFilePath();
    $url = $event->getUrl();
    $rid = $event->getRevisionId();

    try {
      $res = $this->client->sendFile($file, $url);
    }
    catch (InvalidPayload $error) {
      $this->logger->error($error->getMessage());
    }
    catch (Exception $error) {
      $this->logger->error($error->getMessage());
    }

    return $res;
  }

  /**
   * Trigger an API request to unpublish a route.
   */
  public function onUnpublish(QuantEvent $event) {
    $url = $event->getLocation();

    try {
      $res = $this->client->unpublish($url);
    }
    catch (Exception $error) {
      $this->logger->error($error->getMessage());
    }

    return $res;
  }

}
