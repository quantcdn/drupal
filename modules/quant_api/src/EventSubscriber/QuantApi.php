<?php

namespace Drupal\quant_api\EventSubscriber;

use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\quant_api\Exception\InvalidPayload;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\quant\Plugin\QueueItem\FileItem;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\Seed;

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
   * QuantAPI event subscriber.
   *
   * Listens to Quant events and triggers requests to the configured
   * API endpoint for different operations.
   *
   * @param \Drupal\quant_api\Client\QuantClientInterface $client
   *   The Drupal HTTP Client to make requests.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   * @param \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $event_dispatcher
   *   The event dispatcher.
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
    $events[QuantEvent::OUTPUT] = ['onOutput', -999];
    $events[QuantFileEvent::OUTPUT] = ['onMedia', -999];
    $events[QuantRedirectEvent::UPDATE] = ['onRedirect', -999];
    $events[QuantEvent::UNPUBLISH] = ['onUnpublish', -999];
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
      'redirect_http_code' => (int) $statusCode,
      'published' => TRUE,
    ];

    try {
      $res = $this->client->sendRedirect($data);
    }
    catch (\Exception $error) {
      $this->logger->error($error->getMessage());
      return;
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
      'proxy_override' => $meta['proxy_override'],
    ];

    if (isset($meta['search_record'])) {
      $data['search_record'] = $meta['search_record'];
    }

    if (isset($meta['content_timestamp'])) {
      $data['content_timestamp'] = $meta['content_timestamp'];
    }

    if (isset($meta['content_type'])) {
      $data['headers']['content_type'] = $meta['content_type'];
    }

    if (!empty($rid = $event->getRevisionId())) {
      $data['revision'] = intval($rid);
    }

    try {
      $res = $this->client->send($data);
    }
    catch (\Exception $error) {
      $this->logger->error($error->getMessage());
      return FALSE;
    }

    $media = array_merge($res['attachments']['js'], $res['attachments']['css'], $res['attachments']['media']['images'], $res['attachments']['media']['documents'], $res['attachments']['media']['video']);

    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get('quant_seed_worker');

    foreach ($media as $item) {
      // @todo Determine local vs. remote.
      // @todo Configurable to disallow remote files.
      // @todo Strip base domain.
      $url = urldecode($item['path']);

      if ($url == '/css') {
        continue;
      }

      // Ignore anything that isn't relative for now.
      if (substr($url, 0, 1) != '/' || substr($url, 0, 2) == '//') {
        continue;
      }

      // Strip query params.
      $file = strtok($url, '?');

      // Resolve to a path on disk.
      $fileOnDisk = DRUPAL_ROOT . $file;

      // Override if this looks like a private file.
      if (strpos($file, '/system/files/') !== FALSE) {
        $privatePath = \Drupal::service('file_system')->realpath("private://");
        $fileOnDisk = str_replace('/system/files', $privatePath, $file);
      }

      if (isset($item['existing_md5'])) {
        if (file_exists($fileOnDisk) && md5_file($fileOnDisk) == $item['existing_md5']) {
          continue;
        }
      }

      // If the file exists we send it directly to quant otherwise we add it
      // to the queue to generate assets on the next run.
      if (file_exists($fileOnDisk)) {
        $this->eventDispatcher->dispatch(new QuantFileEvent($fileOnDisk, $item['full_path'] ?? $file), QuantFileEvent::OUTPUT);
      }
      else {
        $file_item = new FileItem([
          'file' => $file,
          'url' => $url,
          'full_path' => $item['full_path'] ?? NULL,
        ]);
        $queue->createItem($file_item);
      }
    }

    // Pagination support.
    $document = new \DOMDocument();
    @$document->loadHTML($content);
    $xpath = new \DOMXPath($document);

    $xpath_selectors = [];
    $links_config = \Drupal::config('quant.settings')->get('xpath_selectors');

    foreach (explode(PHP_EOL, $links_config) as $links_line) {
      $xpath_selectors[] = trim($links_line);
    }

    foreach ($xpath_selectors as $xpath_query) {
      /** @var \DOMElement $node */
      foreach ($xpath->query($xpath_query) as $node) {
        $original_href = $new_href = $node->getAttribute('href');
        if ($original_href[0] === '?') {
          $new_href = strtok($path, '?') . $original_href;
        }

        $queue->createItem(new RouteItem(['route' => $new_href]));
      }
    }

    // Media oEmbed support.
    // Core media may be embedded via iFrame not included by the seed process.
    // This content can be detected and included on the fly.
    /** @var \DOMElement $node */
    foreach ($xpath->query('//iframe[contains(@src, "/media/oembed")]') as $node) {
      $oembed_url = $new_href = $node->getAttribute('src');
      $oembed_item = new RouteItem(['route' => $oembed_url]);
      $oembed_item->send();
    }

    // @todo Report on forms that need proxying (attachments.forms).
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

    // Disallow file sending that does not return 200.
    if (!Seed::headRoute($url)) {
      $this->logger->error("Error retrieving file for route: $url");
      return;
    }

    // Ensure query params are stripped here.
    // The HEAD operation uses full_path which includes itok token.
    $url = strtok($url, '?');

    try {
      $res = $this->client->sendFile($file, $url);
    }
    catch (InvalidPayload $error) {
      $this->logger->error($error->getMessage());
      return;
    }
    catch (\Exception $error) {
      if (strpos("MD5 already matches", $error->getMessage()) !== FALSE) {
        $this->logger->error($error->getMessage());
      }
      return;
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
    catch (\Exception $error) {
      $this->logger->error($error->getMessage());
      return;
    }

    return $res;
  }

}
