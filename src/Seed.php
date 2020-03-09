<?php

namespace Drupal\quant;


use Drupal\node\Entity\Node;
use Drupal\quant\Event\NodeInsertEvent;

class Seed {

  public static function exportNode($node, &$context){
    $vid = $node->get('vid')->value;
    $message = "Processing {$node->title->value} (Revision: {$vid})";

    // Export via event dispatcher.
    \Drupal::service('event_dispatcher')->dispatch(NodeInsertEvent::NODE_INSERT_EVENT, new NodeInsertEvent($node));

    $results = [$node->nid->value];
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
}
