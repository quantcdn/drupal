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
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;

// @todo: Remove superfluous services.
// @todo: Remove unused use statements.

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
   * Symfony\Component\HttpKernel\HttpKernelInterface definition.
   *
   * @var Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

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
  public function __construct(ConfigFactory $config_factory, EntityManagerInterface $entity_manager, RendererInterface $renderer, HtmlRenderer $html_renderer, $display_variant, ThemeInitializationInterface $theme_init, ThemeManagerInterface $theme_manager, AccountSwitcherInterface $account_switcher, HttpKernelInterface $http_kernel) {
    $this->configFactory = $config_factory;
    $this->entityManager = $entity_manager;
    $this->renderer = $renderer;
    $this->htmlRenderer = $html_renderer;
    $this->displayVariant = $display_variant;
    $this->themeInit = $theme_init;
    $this->themeManager = $theme_manager;
    $this->accountSwitcher = $account_switcher;
    $this->httpKernel = $http_kernel;

    $this->activeTheme = $theme_manager->getActiveTheme()->getName();
    $this->frontendTheme = $config_factory->get('system.theme')->get('default');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_kernel.basic')
    );
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
    // Note this is node only.
    // @todo: Rename to something node-specific (extensible to other entities?)
    $nid = $entity->get('nid')->value;
    $rid = $entity->get('vid')->value;

    // The kernel sub-request still requires a theme switch.
    $this->switchTheme();

    // Sub-request needs full domain, redirects to localhost otherwise
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $sub_request = Request::create($host . "/node/{$nid}/quant/{$rid}", 'GET');
    $subResponse = $this->httpKernel->handle($sub_request, HttpKernelInterface::SUB_REQUEST);
    $html = $subResponse->getContent();

    return $html;
  }

}
