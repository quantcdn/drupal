<?php

namespace Drupal\quant\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\node\Controller\NodeViewController;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines a controller to render a single node.
 */
class QuantNodeViewController extends NodeViewController {

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
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, AccountInterface $current_user = NULL, EntityRepositoryInterface $entity_repository = NULL, RequestStack $request_stack, CurrentRouteMatch $route_match, AccountSwitcherInterface $account_switcher) {
    parent::__construct($entity_type_manager, $renderer, $current_user, $entity_repository);

    $this->accountSwitcher = $account_switcher;
    $this->revisionId = $route_match->getParameter('quant_revision_id');

    if ($request_stack->getCurrentRequest()->query->has('quant_revision')) {
      $this->revisionId = intval($request_stack->getCurrentRequest()->query->get('quant_revision'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('current_user'),
      $container->get('entity.repository'),
      $container->get('request_stack'),
      $container->get('current_route_match'),
      $container->get('account_switcher')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $node, $view_mode = 'full', $langcode = NULL) {
    if (!empty($this->revisionId)) {
      $rev = $this->entityTypeManager->getStorage('node')->loadRevision($this->revisionId);
      $lang = $node->language()->getId();
      $node = $rev->getTranslation($lang);
      $this->accountSwitcher->switchTo(new AnonymousUserSession());
    }

    return parent::view($node, $view_mode, $langcode);
  }

}
