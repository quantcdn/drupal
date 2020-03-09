<?php

namespace Drupal\quant;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Render\MainContent\HtmlRenderer;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * The entity renderer service for Quant.
 */
class EntityRenderer implements EntityRendererInterface {

  use StringTranslationTrait;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The entity manager object.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The base renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The HTML Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $htmlRenderer;


  /**
   * The display variant service.
   *
   * @var string
   */
  protected $displayVariant;

  /**
   * Theme initalization object.
   *
   * @var \Drupal\Core\Theme\ThemeInitializationInterface
   */
  protected $themeInit;

  /**
   * The theme manager.
   *
   * @var Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface
   */
  protected $accountSwitcher;

  /**
   * Build the entity renderer service.
   *
   * @param Drupal\Core\Config\ConfigFactory $config_factory
   *   The configuration factory.
   * @param Drupal\Core\Render\RendererInterface $renderer
   *   The baes renderer object.
   * @param Drupal\Core\Render\HtmlRenderer $html_renderer
   *   The HTML renderer object.
   * @param string $display_variant
   *   The theme variant.
   * @param Drupal\Core\Theme\ThemeInitializationInterface $theme_init
   *   The theme initializatio nobject.
   * @param Drupal\Core\Theme\ThemeManagerInterface $theme_manager
   *   The theme manager.
   * @param Drupal\Core\Session\AccountSwitcherInterface $account_switcher
   *   The accounnt switcher.
   */
  public function __construct(ConfigFactory $config_factory, EntityManagerInterface $entity_manager, RendererInterface $renderer, HtmlRenderer $html_renderer, $display_variant, ThemeInitializationInterface $theme_init, ThemeManagerInterface $theme_manager, AccountSwitcherInterface $account_switcher) {
    $this->configFactory = $config_factory;
    $this->entityManager = $entity_manager;
    $this->renderer = $renderer;
    $this->htmlRenderer = $html_renderer;
    $this->displayVariant = $display_variant;
    $this->themeInit = $theme_init;
    $this->themeManager = $theme_manager;
    $this->accountSwitcher = $account_switcher;

    $this->activeTheme = $theme_manager->getActiveTheme()->getName();
    $this->frontendTheme = $config_factory->get('system.theme')->get('default');
  }

  /**
   * Create a render array for the entity.
   *
   * @see entity_view
   *
   * @return array
   *   A renderable array.
   */
  protected function entityView(EntityInterface $entity, $view_mode = 'full', $langcode = NULL) {
    $render_controller = \Drupal::entityManager()
      ->getViewBuilder($entity->getEntityTypeId());

    // @TODO: Validate cache clear.
    // @see entity_view.

    return $render_controller
      ->view($entity, $view_mode, $langcode);
  }

  /**
   * Switch the theme.
   */
  protected function switchTheme($first = FALSE) {
    $theme_name = $first ? $this->activeTheme : $this->frontendTheme;
    $active_theme = $this->themeInit->initTheme($theme_name);
    $this->themeManager->setActiveTheme($active_theme);
  }

  protected function removeKeyFromArray($key, &$array) {
    foreach ($array as $k => $arr) {
      if (is_array($arr)) {
        if (isset($arr[$key])) {
          unset($arr[$key]);
        } else {
          $this->removeKeyFromArray($key, $arr[$k]);
        }
      }
    }
  }


  /**
   * {@inheritdoc}
   */
  public function render(EntityInterface $entity) : string {
    // Make sure notices and other PHP warnings are not surfaced
    // during the static render.
    // @TODO: We should probably try and log this.
    error_reporting(0);
    $main_content = $this->entityView($entity);

    // Switch to the anonymous session.
    $this->accountSwitcher->switchTo(new AnonymousUserSession());
    $this->switchTheme();

    $context = new RenderContext();

    // @TODO: Variant ID service for this.
    $variant_id = 'block_page';

    \Drupal::messenger()->deleteAll();

    $this->renderer->executeInRenderContext($context, function () use (&$main_content) {
      $this->renderer->render($main_content);
    });

    // Get render cache.
    $renderCache = \Drupal::service('render_cache');
    $main_content = $renderCache->getCacheableRenderArray($main_content) + [
      '#title' => isset($main_content['#title']) ? $main_content['#title'] : NULL,
    ];

    // @TODO: Change how to grab the title.
    $title = [
      '#markup' => $entity->getTitle(),
    ];
    $this->renderer->renderPlain($title);
    $title = $title['#markup'];

    $page_display = $this->displayVariant->createInstance($variant_id);
    $page_display
      ->setMainContent($main_content)
      ->setTitle($title);

    $page = [
      '#type' => 'page',
    ];
    $page += $page_display->build();

    // Get regions for the currently active theme.
    $regions = $this->themeManager->getActiveTheme()->getRegions();

    foreach ($regions as $region) {
      if (!empty($page[$region])) {
        $page[$region]['#theme_wrappers'][] = 'region';
        $page[$region]['#region'] = $region;
      }
    }

    // @TODO: This should give us attachments (css+js) for the page so we can
    // add this to the page attachments.
    $this->htmlRenderer->invokePageAttachmentHooks($page);

    // @todo: Determine which approach is better.
    // bare_html_page_renderer replaces the placeholders with correct values
    $bb = \Drupal::service('bare_html_page_renderer')
      ->renderBarePage(
        $page,
        'Page label',
        'page',
        []
      );
    $this->accountSwitcher->switchBack();
    return $bb->getContent();
    // END: End of bare_html_page_renderer hax.

    $html = [
      'page' => $page,
      '#type' => 'html',
    ];

    // Go back to the logged in session.

    $this->htmlRenderer->buildPageTopAndBottom($html);
    system_page_attachments($html['page']);
    $output = $this->renderer->renderPlain($html);
    $this->accountSwitcher->switchBack();

    return $output;
  }

}
