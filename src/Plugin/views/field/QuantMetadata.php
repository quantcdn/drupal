<?php

namespace Drupal\quant\Plugin\views\field;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;
use Drupal\redirect\Entity\Redirect;
use Drupal\taxonomy\TermInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\quant\Utility;

/**
 * Handler for showing Quant metadata.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("quant_metadata")
 */
class QuantMetadata extends FieldPluginBase {

  /**
   * The metadata for the results.
   *
   * @var array
   */
  protected $metadata = [];

  /**
   * The metadata options.
   *
   * @var array
   */
  protected $metadataOptions = [
    'byte_length' => 'Byte Length',
    'content_timestamp' => 'Content Timestamp',
    'date_timestamp' => 'Date Timestamp',
    'highest_revision_number' => 'Highest Revision Number',
    'md5' => 'MD5',
    'published' => 'Published',
    'published_revision' => 'Published Revision',
    'redirect_http_code' => 'Redirect HTTP Code',
    'redirect_url' => 'Redirect URL',
    'revision_count' => 'Revision Count',
    'revision_number' => 'Revision Number',
    'seq_num' => 'Sequence number',
    'type' => 'Type',
    'url' => 'URL',
  ];

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    // @todo Remove `redirect_http_code` and `redirect_url` when view is not for redirects.
    $options['quant_metadata'] = [
      'default' => [
        'url',
        'published',
        'content_timestamp',
        'date_timestamp',
      ],
    ];

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    $form['quant_metadata'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Metadata to show'),
      '#options' => $this->metadataOptions,
      '#default_value' => $this->options['quant_metadata'],
      '#description' => $this->t('Metadata that will be shown along with the entity.'),
    ];

    parent::buildOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Do nothing.
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(&$values) {

    // @todo Only load if handling files.
    $imageStyles = ImageStyle::loadMultiple();

    // Get all URLs for the results.
    $urls = [];
    foreach ($values as $row) {
      // Only process entities.
      if (!isset($row->_entity)) {
        continue;
      }
      $entity = $row->_entity;

      // Handle URLs based on entity type.
      $url = '';
      // Nodes and terms will have the langcode in their URLs.
      if ($entity instanceof NodeInterface || $entity instanceof TermInterface) {
        $url = $entity->toUrl()->toString();
      }
      // Add the langcode for redirects when using multilingual prefixes.
      elseif ($entity instanceof Redirect) {
        $url = $entity->getSourceUrl();
        if (Utility::usesLanguagePathPrefixes()) {
          $url = '/' . $entity->language()->getId() . $url;
        }
      }
      // Image files may have any number of image styles. Don't need to handle
      // media entities as the underlying image files are covered here.
      elseif ($entity instanceof FileInterface) {
        $url = $entity->createFileUrl();
        // For images, add image styles.
        foreach ($imageStyles as $style) {
          $urlParts = parse_url(ImageStyle::load($style->getName())->buildUrl($entity->getFileUri()));
          $styleUrl = $urlParts['path'];
          if ($styleUrl) {
            $urls[$styleUrl] = $entity->id();
          }
        }
      }
      else {
        // Don't process other types.
        continue;
      }

      // Don't process if URL is not found. Shouldn't happen.
      if (empty($url)) {
        continue;
      }

      $urls[$url] = $entity->id();
    }

    // Don't process if no URLs are found. Shouldn't happen.
    if (!$urls) {
      return;
    }

    // Get all the metadata in one call for better performance.
    $client = \Drupal::service('quant_api.client');
    $response = $client->getUrlMeta(array_keys($urls));

    // Don't process if no results are found. This can happen if the data hasn't
    // made it into Quant due to being unpublished or not seeded.
    if (!isset($response['global_meta']['records'])) {
      return;
    }

    // Only keep the metadata for the configured options.
    foreach ($response['global_meta']['records'] as $record) {

      // Don't process if no URL is found. Shouldn't happen.
      if (!isset($record['meta']['url'])) {
        continue;
      }

      $url = $record['meta']['url'];
      $entityId = $urls[$url];

      // Only capture the metadata enabled in the options.
      foreach ($this->options['quant_metadata'] as $key => $value) {
        if (is_string($key) && $value && isset($record['meta'][$key])) {

          // Nest the data under URL to handle image styles URLs too.
          $this->metadata[$entityId][$url][$key] = $record['meta'][$key];
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);

    // Don't process if there is no entity or metadata. This can happen if the
    // data hasn't made it into Quant due to being unpublished or not seeded.
    if (!$entity || !$this->metadata || !isset($this->metadata[$entity->id()])) {
      return [
        '#theme' => 'item_list',
        '#list_type' => 'ul',
        '#items' => [$this->t('No metadata found.')],
        '#attributes' => ['class' => ['quant-metadata-list']],
      ];
    }

    // Handle special formatting.
    $metadataTypes = [
      'byte_length' => 'integer',
      'content_timestamp' => 'timestamp',
      'date_timestamp' => 'timestamp',
      'highest_revision_number' => 'integer',
      'md5' => 'string',
      'published' => 'boolean',
      'published_revision' => 'integer',
      'redirect_http_code' => 'integer',
      'redirect_url' => 'string',
      'revision_count' => 'integer',
      'revision_number' => 'integer',
      'seq_num' => 'integer',
      'type' => 'string',
      'url' => 'string',
    ];

    // Gather the metadata for each URL.
    $build = [];
    $numUrls = count($this->metadata[$entity->id()]);
    foreach ($this->metadata[$entity->id()] as $url => $data) {

      $items = [];
      foreach ($this->options['quant_metadata'] as $optionKey => $optionValue) {
        if (is_string($optionKey) && $optionValue) {
          // If there is no data for this option, skip it.
          if (!isset($data[$optionKey])) {
            continue;
          }
          $metadataValue = $data[$optionKey];
          // If there is no type for this option, skip it. Shouldn't happen.
          if (!isset($metadataTypes[$optionKey])) {
            continue;
          }
          // Handle specific formats.
          if ($metadataValue && $metadataTypes[$optionKey] === 'timestamp') {
            $metadataValue = DrupalDateTime::createFromTimestamp($metadataValue)->format('Y-m-d H:i:s');
          }
          elseif ($metadataTypes[$optionKey] === 'boolean') {
            $metadataValue = $metadataValue ? $this->t('Yes') : $this->t('No');
          }
          $items[] = ['#markup' => '<strong>' . $this->metadataOptions[$optionKey] . ':</strong> ' . $metadataValue];
        }
      }

      // File entities can have multiple URLs so format these differently.
      $data = [];
      if ($numUrls > 1) {
        $data['url'] = ['#markup' => $this->t('<strong>URL:</strong> :url', [':url' => $url])];
      }
      $data['metadata'] = [
        '#theme' => 'item_list',
        '#list_type' => 'ul',
        '#items' => $items,
        '#attributes' => ['class' => ['quant-metadata-list']],
      ];
      $build[] = $data;
    }

    return $build;
  }

}
