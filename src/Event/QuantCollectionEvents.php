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
  const REDIRECTS = 'quant.seed.redirects';

  /**
   * Name of the event when collecting entities.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectEntitiesEvent
   *
   * @var string
   */
  const ENTITIES = 'quant.seed.entities';

  /**
   * Name of the event when collecting files.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectFilesEvent
   *
   * @var string
   */
  const FILES = 'quant.seed.files';

  /**
   * Name of the event when collecting routes.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectRoutesEvent
   *
   * @var string
   */
  const ROUTES = 'quant.seed.routes';

  /**
   * Name of the event when collecting the routes to push as files.
   *
   * @Event
   *
   * @see Drupal\quant\Event\CollectRoutesEvent
   *
   * @var string
   */
  const BINARY_ROUTES = 'quant.seed.binary_routes';

}
