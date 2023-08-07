<?php

namespace Drupal\quant_tome;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\quant\Plugin\QueueItem\RedirectItem;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant_api\Client\QuantClient;
use Drupal\tome_base\PathTrait;
use Drupal\tome_static\StaticGeneratorInterface;

/**
 * Batch process for Tome static content.
 */
class QuantTomeBatch {

  use PathTrait;
  use DependencySerializationTrait;

  /**
   * The static generator.
   *
   * @var \Drupal\tome_static\StaticGeneratorInterface
   */
  protected $static;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The Quant API client.
   *
   * @var \Drupal\quant_api\Client\QuantClient
   */
  protected $client;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructor.
   *
   * @param \Drupal\tome_static\StaticGeneratorInterface $static
   *   The Tome static generator.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system interface.
   * @param \Drupal\quant_api\Client\QuantClient $client
   *   The Quant API client.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(StaticGeneratorInterface $static, FileSystemInterface $file_system, QuantClient $client, QueueFactory $queue_factory) {
    $this->static = $static;
    $this->fileSystem = $file_system;
    $this->client = $client;
    $this->queueFactory = $queue_factory;
  }

  /**
   * Check to see if Quant is configured correctly.
   *
   * @return bool
   *   If we can connect to the Quant API.
   */
  public function checkConfig() {
    return $this->client->ping();
  }

  /**
   * Determine if Tome export exists.
   *
   * @return bool
   *   State of the export location.
   */
  public function checkBuild() {
    return file_exists($this->static->getStaticDirectory());
  }

  /**
   * Generate the batch to seed Tome exports.
   *
   * @return \Drupal\Core\Batch\BatchBuilder
   *   A batch builder object.
   */
  public function getBatch() {
    $batch_builder = new BatchBuilder();
    $files = [];

    foreach ($this->fileSystem->scanDirectory($this->static->getStaticDirectory(), '/.*/') as $file) {
      $files[] = $file->uri;
    }

    foreach (array_chunk($files, 10) as $chunk) {
      $batch_builder->addOperation([$this, 'getHashes'], [$chunk]);
    }

    $batch_builder->addOperation([$this, 'checkRequiredFiles']);
    return $batch_builder;
  }

  /**
   * Generate hashes of the files.
   *
   * Generate hashes as Quant's API would for the file content. This will reduce
   * the number of files that we need to seed in the final batch operation.
   *
   * @todo Quant meta look up or local?
   *
   * @param array $files
   *   List of file URIs.
   * @param array|\ArrayAccess &$context
   *   The batch context.
   */
  public function getHashes(array $files, &$context) {
    $file_hashes = [];
    foreach ($files as $file) {
      $file_hashes[$file] = md5(file_get_contents($file));
    }

    $context['results']['files'] = $context['results']['files'] ?? [];
    $context['results']['files'] = array_merge($context['results']['files'], $file_hashes);
  }

  /**
   * Processes the hashed records and generates the deploy batch.
   *
   * Takes the computed file hashes and evaluates which files need to be sent
   * to Quant. Then, it creates another batch operation to seed the data.
   *
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function checkRequiredFiles(&$context) {
    $file_hashes = $context['results']['files'];

    $queue = $this->queueFactory->get('quant_seed_worker');
    $queue->deleteQueue();

    foreach ($file_hashes as $file_path => $hash) {
      if (strpos($file_path, 'redirect') > -1) {
        if ($handle = fopen($file_path, 'r')) {
          while (!feof($handle)) {
            $line = fgets($handle);
            $redirect = explode(' ', $line);
            $source = trim($redirect[0]);
            if (empty($source)) {
              break;
            }
            // Only use the destination URI.
            $destination = parse_url(trim($redirect[1]), PHP_URL_PATH);
            $queue->createItem(new RedirectItem([
              'source' => $source,
              'destination' => $destination,
              'status_code' => 301,
            ]));
          }
        }
        fclose($handle);
        continue;
      }

      $uri = $this->pathToUri($file_path);
      $item = new RouteItem([
        'route' => $uri,
        'uri' => $uri,
        'file_path' => $file_path,
      ]);

      $queue->createItem($item);
    }
  }

  /**
   * Convert the path to a URI.
   *
   * @param string $file_path
   *   The file path.
   *
   * @return string
   *   URI based on the file path.
   */
  public function pathToUri($file_path) {
    // Strip directory and index.html to match regular Quant processing.
    $uri = str_replace($this->static->getStaticDirectory(), '', $file_path);
    $uri = str_replace('/index.html', '', $uri);
    return $uri;
  }

  /**
   * Deploy a file to Quant.
   *
   * @var \Drupal\quant\Plugin\QueueItem $item
   *   The file item to send to Quant API.
   */
  public function deploy($item, array &$context) {
    \Drupal::logger('quant_tome')->notice('Sending %s', [
      '%s' => $item->log(),
    ]);
    $item->send();
  }

  /**
   * Finish deploy process.
   *
   * @param bool $success
   *   TRUE if batch successfully completed.
   * @param array $context
   *   Batch context.
   */
  public function finish($success, array &$context) {
    if ($success) {
      \Drupal::logger('quant_tome')->info('Complete!');
    }
    else {
      \Drupal::logger('quant_tome')->error('Failed to deploy all files, check the logs!');
    }
  }

}
