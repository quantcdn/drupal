<?php

namespace Drupal\quant_tome;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\tome_base\PathTrait;
use Drupal\tome_static\StaticGeneratorInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\File\FileSystemInterface;
use Drupal\quant\Plugin\QueueItem\RouteItem;
use Drupal\quant_api\Client\QuantClient;

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
   * Constructor.
   *
   * @param \Drupal\tome_static\StaticGeneratorInterface $static
   *   The tome static generator.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system interface.
   * @param \Drupal\quant_api\Client\QuantClient $client
   *   The Quant api client.
   */
  public function __construct(StaticGeneratorInterface $static, FileSystemInterface $file_system, QuantClient $client) {
    $this->static = $static;
    $this->fileSystem = $file_system;
    $this->client = $client;
  }

  /**
   * Check to see if quant is configured correctly.
   *
   * @return bool
   *   If we can connect to the Quant api.
   */
  public function checkConfig() {
    return $this->client->ping();
  }

  /**
   * Determine if tome export exists.
   *
   * @return bool
   *   State of the export location.
   */
  public function checkBuild() {
    return file_exists($this->static->getStaticDirectory());
  }

  /**
   * Generate the batch to seed tome exports.
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
   * Generate hashes as Quant's API would for the file content.
   * This will reduce the number of files that we need to seed
   * in the final batch operation.
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

    // @TODO: Quant meta look up or local?

    $context['results']['files'] = isset($context['results']['files']) ? $context['results']['files'] : [];
    $context['results']['files'] = array_merge($context['results']['files'], $file_hashes);
  }

  /**
   * Processes the hashed records and generates the deploy batch.
   *
   * Takes the computed file hashes and evaluates which files
   * need to be sent back to Quant. Will then create another batch
   * operation to seed the data.
   *
   * @param array|\ArrayAccess $context
   *   The batch context.
   */
  public function checkRequiredFiles(&$context) {
    $file_hashes = $context['results']['files'];

    foreach ($file_hashes as $file_path => $hash) {
      // @TODO: Quant meta check.
      // unset($file_hashes[$file_path]);
    }

    $batch_builder = new BatchBuilder();
    foreach ($file_hashes as $file_path => $hash) {
      $item = new RouteItem([
        'route' => $this->pathToUri($file_path),
        'uri' => $this->pathToUri($file_path),
        'file_path' => $file_path,
      ]);
      $batch_builder->addOperation([$this, 'deploy'], [$item]);
    }

    batch_set($batch_builder->toArray());
  }

  /**
   * Convert the path to a URI used by Quant.
   *
   * @param string $file_path
   *   The file path.
   *
   * @return string
   */
  public function pathToUri($file_path) {
    return str_replace($this->static->getStaticDirectory(), '', $file_path);
  }

  /**
   * Deploy a file to Quant.
   *
   * @var \Drupal\quant\Plugin\QueueItem $item
   *   The file item to send to Quants API.
   */
  public function deploy($item, &$context) {
    \Drupal::logger('quant_tome')->notice('Sending %s', [
      '%s' => $item->log(),
    ]);
    $item->send();
  }

}
