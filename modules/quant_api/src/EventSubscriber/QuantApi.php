<?php

namespace Drupal\quant_api\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\quant\Event\QuantEvent;
use Drupal\quant\Event\QuantFileEvent;
use Drupal\quant\Event\QuantRedirectEvent;
use Drupal\quant\Plugin\QueueItem\FileItem;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant\QuantQueueFactory;
use Drupal\quant\Seed;
use Drupal\quant\Utility;
use Drupal\quant_api\Client\QuantClientInterface;
use Drupal\quant_api\Exception\InvalidPayload;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
   * @var \Symfony\Component\EventDispatcher\EventDispatcher
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
   * @param \Symfony\Component\EventDispatcher\EventDispatcher $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(QuantClientInterface $client, LoggerChannelFactoryInterface $logger_factory, EventDispatcher $event_dispatcher) {
    $this->client = $client;
    $this->logger = $logger_factory->get('quant_api');
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
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
    // Normalise before the self-redirect check, so that a malformed source
    // like //fr/node/1 is recognised as the same path as its destination
    // rather than published as a redirect to itself.
    $source = Utility::normalizePath($event->getSourceUrl());
    $dest = Utility::normalizePath($event->getDestinationUrl());
    $statusCode = $event->getStatusCode();

    if ($source == $dest) {
      return;
    }

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

    // Last line of defence: a path assembled from an empty prefix or base
    // must not reach the API as //fr/node/1, which would publish a duplicate
    // resource alongside the real one.
    $path = Utility::normalizePath($event->getLocation());
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

    $queue_factory = QuantQueueFactory::getInstance();
    $queue = $queue_factory->get('quant_seed_worker');

    $pushed = $this->pushAttachments($res, $queue);

    // Pagination support.
    $document = new \DOMDocument();
    @$document->loadHTML($content);
    $xpath = new \DOMXPath($document);
    $this->queuePaginationLinks($xpath, $path, $queue);

    // Media oEmbed support.
    // Core media may be embedded via iFrame not included by the seed process.
    // This content can be detected and included on the fly.
    /** @var \DOMElement $node */
    foreach ($xpath->query('//iframe[contains(@src, "/media/oembed")]') as $node) {
      $oembed_url = $new_href = $node->getAttribute('src');
      // Only add if it's a relative URL to handle the case where the URL
      // contains `/media/oembed` but is from another website.
      if (str_starts_with($oembed_url, '/')) {
        $oembed_item = new RouteItem(['route' => $oembed_url]);
        $oembed_item->send();
        $this->logger->notice("[route_item] $oembed_url");
      }
    }

    // Files named only in structured data, such as a media oEmbed thumbnail.
    $this->pushStructuredDataFiles($xpath, $pushed);

    // @todo Report on forms that need proxying (attachments.forms).
  }

  /**
   * Pushes, or queues, the attachments the API found in page markup.
   *
   * @param array $res
   *   The API response to the page push.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue for files that must be generated first.
   *
   * @return array
   *   The local paths of the files handled, as keys.
   */
  protected function pushAttachments(array $res, QueueInterface $queue) : array {
    // A refused write returns an empty array, and the API is entitled to
    // answer without attachments, so none of these keys can be assumed.
    // array_merge() fatals on a NULL argument.
    $attachments = $res['attachments'] ?? [];
    $media = array_merge(
      $attachments['js'] ?? [],
      $attachments['css'] ?? [],
      $attachments['media']['images'] ?? [],
      $attachments['media']['documents'] ?? [],
      $attachments['media']['video'] ?? []
    );

    $pushed = [];
    foreach ($media as $item) {
      $file = $this->pushAttachment($item, $queue);
      if ($file) {
        $pushed[$file] = TRUE;
      }
    }

    return $pushed;
  }

  /**
   * Queues the pagination links that the xpath_selectors setting selects.
   *
   * @param \DOMXPath $xpath
   *   The page document.
   * @param string $path
   *   The page path.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The seed queue.
   */
  protected function queuePaginationLinks(\DOMXPath $xpath, string $path, QueueInterface $queue) : void {
    $links_config = \Drupal::config('quant.settings')->get('xpath_selectors');

    foreach (explode(PHP_EOL, (string) $links_config) as $links_line) {
      /** @var \DOMElement $node */
      foreach ($xpath->query(trim($links_line)) as $node) {
        $original_href = $new_href = $node->getAttribute('href');
        if ($original_href[0] === '?') {
          $new_href = strtok($path, '?') . $original_href;
        }

        $queue->createItem(new RouteItem(['route' => $new_href]));
      }
    }
  }

  /**
   * Pushes, or queues, one attachment the API found in page markup.
   *
   * @param array $item
   *   The attachment, with path, original_path and optionally full_path and
   *   existing_md5.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue for files that must be generated first.
   *
   * @return string|null
   *   The local path of the file, or NULL if the attachment was skipped.
   */
  protected function pushAttachment(array $item, QueueInterface $queue) : ?string {
    // @todo Configurable to disallow remote files.
    $url = urldecode($item['path']);

    // Strip query params, and resolve to a path on disk.
    $file = (string) strtok($url, '?');
    $fileOnDisk = $this->diskPath($file);

    if (!$this->isLocalAttachment($item, $url, $fileOnDisk)) {
      return NULL;
    }

    // Check if the file has changed.
    if (isset($item['existing_md5']) && file_exists($fileOnDisk) && md5_file($fileOnDisk) == $item['existing_md5']) {
      return $file;
    }

    $this->generateAggregate($item, $file, $fileOnDisk);

    // If the file exists, send it directly to Quant; otherwise, add it to the
    // queue to generate assets on the next run.
    if (file_exists($fileOnDisk)) {
      $this->eventDispatcher->dispatch(new QuantFileEvent($fileOnDisk, $item['full_path'] ?? $file), QuantFileEvent::OUTPUT);
    }
    else {
      $queue->createItem(new FileItem([
        'file' => $file,
        'url' => $url,
        'full_path' => $item['full_path'] ?? NULL,
        'original_path' => $item['original_path'] ?? NULL,
      ]));
    }

    return $file;
  }

  /**
   * Checks if an attachment is a file of this site.
   *
   * @param array $item
   *   The attachment.
   * @param string $url
   *   The decoded attachment path.
   * @param string $fileOnDisk
   *   The path on disk the attachment resolves to.
   *
   * @return bool
   *   TRUE if the attachment should be pushed.
   */
  protected function isLocalAttachment(array $item, string $url, string $fileOnDisk) : bool {
    // Ignore anything that isn't relative for now.
    if ($url == '/css' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
      return FALSE;
    }

    // Do not include external items.
    if (!Utility::isExternalUrl($item['original_path'])) {
      return TRUE;
    }

    // Content often links a file by another of this site's hostnames, such
    // as the editing domain. Those are local files, provided they are on disk.
    return Utility::isTrustedHostUrl($item['original_path']) && is_file($fileOnDisk);
  }

  /**
   * Generates a missing on-demand CSS/JS aggregate.
   *
   * Since Drupal 10.1, CSS/JS aggregates are generated on demand and may
   * not exist on disk. Generate from the original path, which carries
   * the query parameters the asset controller requires. The aggregate is
   * persisted under the exact filename the markup references, even when
   * library definitions have changed and core would only write it under
   * a corrected filename.
   *
   * @param array $item
   *   The attachment.
   * @param string $file
   *   The attachment path without a query string.
   * @param string $fileOnDisk
   *   The path on disk to write the aggregate to.
   */
  protected function generateAggregate(array $item, string $file, string $fileOnDisk) : void {
    // Only process internal CSS/JS files.
    $check_file = (str_ends_with($file, 'css') || str_ends_with($file, 'js')) && !str_starts_with($item['original_path'], 'http');
    if (!$check_file || file_exists($fileOnDisk)) {
      return;
    }

    if (!\Drupal::service('quant.asset_generator')->generate($item['original_path'], $fileOnDisk)) {
      $this->logger->error("Error generating asset: " . $item['original_path']);
    }
  }

  /**
   * Pushes local public files that are named only in JSON-LD.
   *
   * The API finds attachments in elements such as img and a, but does not
   * read script content. A file named only in structured data, such as the
   * media oEmbed thumbnail in a VideoObject thumbnailUrl, never reaches Quant
   * otherwise.
   *
   * @param \DOMXPath $xpath
   *   The page document.
   * @param array $pushed
   *   Local paths already handled from the attachments, as keys.
   */
  protected function pushStructuredDataFiles(\DOMXPath $xpath, array $pushed) : void {
    $urls = [];
    foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
      $data = json_decode($node->textContent, TRUE);
      if (is_array($data)) {
        array_walk_recursive($data, function ($value) use (&$urls) {
          if (is_string($value)) {
            $urls[$value] = TRUE;
          }
        });
      }
    }

    $files_base = '/' . PublicStream::basePath() . '/';
    foreach (array_keys($urls) as $url) {
      $file = urldecode((string) parse_url($url, PHP_URL_PATH));
      if (isset($pushed[$file]) || !str_starts_with($file, $files_base)) {
        continue;
      }

      $external = Utility::isExternalUrl($url) && !Utility::isTrustedHostUrl($url);
      if (!$external && is_file(DRUPAL_ROOT . $file)) {
        $pushed[$file] = TRUE;
        $this->eventDispatcher->dispatch(new QuantFileEvent(DRUPAL_ROOT . $file, $file), QuantFileEvent::OUTPUT);
      }
    }
  }

  /**
   * Resolves a file URL path to its path on disk.
   *
   * @param string $file
   *   The URL path, without a query string.
   *
   * @return string
   *   The path on disk.
   */
  protected function diskPath(string $file) : string {
    // Override if this looks like a private file.
    if (strpos($file, '/system/files/') !== FALSE) {
      $privatePath = \Drupal::service('file_system')->realpath("private://");
      return str_replace('/system/files', $privatePath, $file);
    }

    return DRUPAL_ROOT . $file;
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
      // An unchanged file is not a failure; anything else must be visible,
      // or the file silently never reaches Quant.
      if (!str_contains($error->getMessage(), 'MD5 already matches')) {
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
      // Don't log it if it's a 404, since the content is unpublished.
      if (!str_contains($error->getMessage(), '404 Not Found') && !str_contains($error->getMessage(), 'Resource is already unpublished')) {
        $this->logger->error($error->getMessage());
      }
      return;
    }

    return $res;
  }

}
