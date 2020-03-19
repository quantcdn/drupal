<?php

namespace Drupal\quant;


use Drupal\node\Entity\Node;
use Drupal\quant\Event\NodeInsertEvent;
use Drupal\quant\Event\QuantFileEvent;

class Seed {

  /**
   * Trigger export node via event dispatcher.
   */
  public static function exportNode($node, &$context){
    $vid = $node->get('vid')->value;
    $message = "Processing {$node->title->value} (Revision: {$vid})";

    // Export via event dispatcher.
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($node));

    $results = [$node->nid->value];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  /**
   * Trigger export file via event dispatcher.
   */
  public static function exportFile($file, &$context) {
    $message = "Processing theme asset: " . basename($file);

    // Export via event dispatcher.
    if (file_exists(DRUPAL_ROOT . $file)) {
      \Drupal::service('event_dispatcher')->dispatch(QuantFileEvent::OUTPUT, new QuantFileEvent(DRUPAL_ROOT . $file, $file));
    }

    $results = [$file];
    $context['message'] = $message;
    $context['results'][] = $results;
  }

  public static function finishedSeedCallback($success, $results, $operations) {
    // The 'success' parameter means no fatal PHP errors were detected. All
    // other error management should be handled using 'results'.
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One item processed.', '@count items processed.'
      );
    }
    else {
      $message = t('Finished with an error.');
    }
    drupal_set_message($message);
  }

  /**
   * Find theme assets.
   * Currently supports fonts: ttf/woff/otf, images: png/jpeg/svg.
   * @todo: Make this configurable.
   */
  public static function findThemeAssets() {
    // @todo: Find path programatically
    // @todo: Support multiple themes (e.g site may have multiple themes changing by route).
    $config = \Drupal::config('system.theme');
    $themeName = $config->get('default');
    $themePath = DRUPAL_ROOT . '/themes/custom/' . $themeName;

    if (!is_dir($themePath)) {
      echo "Theme dir does not exist"; die;
    }

    $files = [];

    $directoryIterator = new \RecursiveDirectoryIterator($themePath);
    $iterator = new \RecursiveIteratorIterator($directoryIterator);
    $regex = new \RegexIterator($iterator, '/^.+(.jpe?g|.png|.svg|.ttf|.woff|.otf)$/i', \RecursiveRegexIterator::GET_MATCH);

    foreach($regex as $name => $r) {
      $files[] = str_replace(DRUPAL_ROOT, '', $name);
    }

    return $files;

  }
}
