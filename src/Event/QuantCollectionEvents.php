<?php

namespace Drupal\quant\Event;

/**
 * Define collection events for Quant.
 */
final class QuantCollectionEvents {

  /**
   * Name of the event when collecting redirects.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectRedirectEvent
   *
   * @var string
   */
  const REDIRECT = 'quant.seed.redirect';

  /**
   * Name of the event when collecting entities.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectEntitiesEvent
   *
   * @var string
   */
  const ENTITY = 'quant.seed.entity';

  /**
   * Name of the event when collecting files.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectFilesEvent
   *
   * @var string
   */
  const FILE = 'quant.seed.file';

  /**
   * Name of the event when collecting routes.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectRoutesEvent
   *
   * @var string
   */
  const ROUTE = 'quant.seed.route';
}
