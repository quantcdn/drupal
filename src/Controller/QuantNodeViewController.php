<?php

namespace Drupal\quant\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Controller\NodeViewController;
use Drupal\Core\Session\AnonymousUserSession;

/**
 * Defines a controller to render a single node.
 */
class QuantNodeViewController extends NodeViewController {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The entity revision id.
   *
   * @var int
   */
  protected $revisionId;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * Creates an NodeViewController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user. For backwards compatibility this is optional, however
   *   this will be removed before Drupal 9.0.0.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, AccountInterface $current_user = NULL, EntityRepositoryInterface $entity_repository = NULL) {
    parent::__construct($entity_type_manager, $renderer);
    $this->currentUser = $current_user ?: \Drupal::currentUser();
    if (!$entity_repository) {
      @trigger_error('The entity.repository service must be passed to NodeViewController::__construct(), it is required before Drupal 9.0.0. See https://www.drupal.org/node/2549139.', E_USER_DEPRECATED);
      $entity_repository = \Drupal::service('entity.repository');
    }
    $this->entityRepository = $entity_repository;
    $this->accountSwitcher = \Drupal::service('account_switcher');
    $this->revisionId = \Drupal::routeMatch()->getParameter('quant_revision_id');

    if ($q = \Drupal::request()->get('quant_revision')) {
      $this->revisionId = intval($q);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $node, $view_mode = 'full', $langcode = NULL) {

    if (!empty($this->revisionId)) {
      // Override the node with a custom revision.
      $node = \Drupal::entityTypeManager()->getStorage('node')->loadRevision($this->revisionId);

      // @todo: AccountSwitcher to render as a QuantUserSession.
      $this->accountSwitcher->switchTo(new AnonymousUserSession());
    }

    return parent::view($node, $view_mode, $langcode);
  }

}
