<?php

namespace Drupal\quant\Page;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Query\PagerSelectExtender;

/**
 * Page controller for the queue info page.
 */
class QueueInfo extends ControllerBase {

  /**
   * Page callback for the queue info page.
   *
   * @return array
   *   A render array.
   */
  public function build() {
    $db = \Drupal::database();
    $query = $db->select('queue', 'q')
      ->condition('name', 'quant_seed_worker')
      ->fields('q', ['item_id', 'name', 'data', 'expire', 'created']);
    $pager = $query->extend(PagerSelectExtender::class)->limit(10);

    $header = [
      $this->t('Item ID'),
      $this->t('Claimed/Expiration'),
      $this->t('Created'),
      $this->t('Content/Data'),
    ];

    $queue_factory = \Drupal::service('queue');
    $queue = $queue_factory->get('quant_seed_worker');

    if ($queue->numberOfItems() > 0) {
      $build['info'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('There are [:total] items in the queue.', [
          ':total' => $queue->numberOfItems(),
        ]) . '</p>',
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#empty' => $this->t('There have been no items added to the queue.'),
    ];

    foreach ($pager->execute()->fetchAllAssoc('item_id') as $item) {
      $handler = unserialize($item->data);
      $build['table'][$item->item_id] = [
        'item_id' => ['#plain_text' => $item->item_id],
        'expire' => ['#plain_text' => 'unclaimed'],
        'created' => ['#plain_text' => date('r', $item->created)],
        'data' => $handler->info(),
      ];
    }

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
