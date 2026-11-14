<?php

namespace Drupal\quant_search\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a search page from one screen with defaults; the page goes live.
 *
 * Saves a quant_search_page (enabled), registers its facets and publishes it.
 * The full form stays available for filters and display details.
 */
class CreateSearchPageForm extends FormBase {

  /**
   * The facet proposer.
   *
   * @var \Drupal\quant_search\FacetProposer
   */
  protected $proposer;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The search page publisher.
   *
   * @var \Drupal\quant_search\SearchPagePublisher
   */
  protected $publisher;

  /**
   * The router, to refuse a path that Drupal already uses.
   *
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * The View converter.
   *
   * @var \Drupal\quant_search\ViewSearchPageConverter
   */
  protected $converter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = new static();
    $instance->proposer = $container->get('quant_search.facet_proposer');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->publisher = $container->get('quant_search.page_publisher');
    $instance->router = $container->get('router.no_access_checks');
    $instance->converter = $container->get('quant_search.view_converter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quant_search_create_page';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $types = array_map(fn ($type) => $type->label(), $this->entityTypeManager->getStorage('node_type')->loadMultiple());
    $indexed = (array) $this->config('quant_search.entities.settings')->get('quant_search_entity_node_bundles');
    $indexed = array_keys(array_filter($indexed));

    $form['#tree'] = FALSE;
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Choose what the page searches. The page publishes to Quant when you save. You can change everything later.') . '</p>',
    ];
    $proposal = $this->proposalFromQuery($form_state);
    if ($proposal !== NULL) {
      $form['from_view'] = $this->buildProposalNotes($proposal);
    }
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page title'),
      '#default_value' => $proposal['values']['title'] ?? $this->t('Search'),
      '#required' => TRUE,
    ];
    $form['route'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Page address'),
      '#field_prefix' => Url::fromRoute('<front>', [], ['absolute' => TRUE])->toString(),
      '#default_value' => $this->defaultRoute($proposal['values']['route'] ?? NULL),
      '#required' => TRUE,
      '#size' => 30,
    ];
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content to search'),
      '#options' => $types,
      '#default_value' => ($proposal['values']['bundles'] ?? []) ?: ($indexed ?: array_keys($types)),
      '#description' => $this->t('Leave all unchecked to search every content type. A type that is not in the search index yet is added; re-index afterwards.'),
    ];
    $form['facets'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Filters beside the results'),
      '#options' => [],
      '#default_value' => [],
    ];
    foreach ($this->proposer->propose([]) as $key => $facet) {
      $form['facets']['#options'][$key] = $facet['label'];
      if ($facet['default'] && $proposal === NULL) {
        $form['facets']['#default_value'][] = $key;
      }
    }
    foreach ($proposal['values']['facets'] ?? [] as $key) {
      $form['facets']['#options'] += [$key => $key];
      $form['facets']['#default_value'][] = $key;
    }
    $form['facet_display'] = [
      '#type' => 'radios',
      '#title' => $this->t('Filter style'),
      '#options' => [
        'checkbox' => $this->t('Checkboxes'),
        'select' => $this->t('Drop-down'),
        'menu' => $this->t('Links'),
      ],
      '#default_value' => 'checkbox',
    ];
    $form['per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Results per page'),
      '#default_value' => $proposal['values']['per_page'] ?? 20,
      '#min' => 5,
      '#max' => 100,
    ];
    $form['block'] = $this->buildBlockChoice();
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create the search page'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * The View proposal named in the query (?view=ID&display=ID), if any.
   *
   * Kept in the form state, so that the submit uses the same proposal.
   */
  protected function proposalFromQuery(FormStateInterface $form_state): ?array {
    if ($form_state->has('from_view')) {
      return $form_state->get('from_view');
    }
    $query = $this->getRequest()->query;
    $view = (string) $query->get('view', '');
    $display = (string) $query->get('display', '');
    if ($view === '' || !isset($this->converter->candidates()[$view . ':' . $display])) {
      return NULL;
    }
    $proposal = $this->converter->propose($view, $display);
    $form_state->set('from_view', $proposal);
    return $proposal;
  }

  /**
   * What the proposal took from the View, and what it could not take.
   */
  protected function buildProposalNotes(array $proposal): array {
    $items = $proposal['notes'];
    $route = (string) $proposal['values']['route'];
    $items[] = $this->t('The View stays at /@route. To give that address to the search page, disable the View page first, then edit the search page.', ['@route' => $route]);
    if ($proposal['values']['manual_filters'] !== '') {
      $items[] = $this->t('The search page keeps the fixed term filter of the View: @filter', [
        '@filter' => $proposal['values']['manual_filters'],
      ]);
    }
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Proposed from the View @label', ['@label' => $proposal['label']]),
      '#items' => $items,
    ];
  }

  /**
   * The "add a search box to every page" choice.
   */
  protected function buildBlockChoice(): array {
    $theme = $this->config('system.theme')->get('default');
    $regions = system_region_list($theme, REGIONS_VISIBLE);
    // One search box is enough: a second page does not propose another. A box
    // whose page was deleted does not count.
    $placed = array_filter($this->entityTypeManager->getStorage('block')->loadByProperties([
      'theme' => $theme,
      'plugin' => 'quant_autocomplete_block',
    ]), fn ($block) => $this->pageExists($block->get('settings')['page'] ?? ''));
    return [
      '#type' => 'container',
      'place_block' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Add a search box with suggestions to every page'),
        '#default_value' => $placed === [],
        '#description' => $placed === [] ? '' : $this->t('This theme already has a Quant search box. Tick this only to add a second one for this page.'),
      ],
      'region' => [
        '#type' => 'select',
        '#title' => $this->t('Region'),
        '#options' => $regions,
        '#default_value' => isset($regions['header']) ? 'header' : array_key_first($regions),
        '#states' => ['visible' => [':input[name="place_block"]' => ['checked' => TRUE]]],
      ],
    ];
  }

  /**
   * The default address: search, or the first free of site-search, search-2...
   *
   * An address is taken when a search page or a Drupal route (for example a
   * Views search page) already uses it.
   */
  protected function defaultRoute(?string $preferred = NULL): string {
    $used = array_map(fn ($page) => $page->get('route'), $this->entityTypeManager->getStorage('quant_search_page')->loadMultiple());
    $first = $preferred ? [$preferred, $preferred . '-search'] : [];
    foreach (array_merge($first, ['search', 'site-search'], array_map(fn ($i) => 'search-' . $i, range(2, 20))) as $route) {
      if (!in_array($route, $used, TRUE) && !$this->isDrupalPath($route)) {
        return $route;
      }
    }
    return 'search-' . time();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $route = self::normaliseRoute((string) $form_state->getValue('route'));
    $form_state->setValue('route', $route);
    if (!preg_match('#^[a-z0-9][a-z0-9/_-]*$#', $route)) {
      $form_state->setErrorByName('route', $this->t('Use lower-case letters, numbers, hyphens and slashes, for example search or news/search.'));
      return;
    }
    $storage = $this->entityTypeManager->getStorage('quant_search_page');
    if ($storage->loadByProperties(['route' => $route]) || $storage->load(self::machineName($route))) {
      $form_state->setErrorByName('route', $this->t('Another search page uses this address.'));
      return;
    }
    if ($this->isDrupalPath($route)) {
      $form_state->setErrorByName('route', $this->t('Drupal already has a page at this address. Choose another one.'));
    }
  }

  /**
   * Whether a path already resolves to a Drupal route.
   */
  protected function isDrupalPath(string $route): bool {
    try {
      $this->router->match('/' . $route);
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $route = (string) $form_state->getValue('route');
    $bundles = array_values(array_filter((array) $form_state->getValue('bundles')));
    $keys = array_values(array_filter((array) $form_state->getValue('facets')));
    $from_view = $form_state->get('from_view');
    $page = $this->entityTypeManager->getStorage('quant_search_page')->create([
      'id' => self::machineName($route),
      'label' => $form_state->getValue('title'),
      'title' => $form_state->getValue('title'),
      'route' => $route,
      'status' => TRUE,
      'languages' => $from_view['values']['languages'] ?? [],
      'bundles' => array_combine($bundles, $bundles) ?: [],
      'manual_filters' => (string) ($from_view['values']['manual_filters'] ?? ''),
      'facets' => $this->proposer->facets($keys, (string) $form_state->getValue('facet_display')),
      'display' => [
        'results' => ['display_search' => TRUE, 'display_stats' => TRUE, 'show_clear_refinements' => TRUE],
        'pagination' => ['pagination_enabled' => TRUE, 'per_page' => (int) $form_state->getValue('per_page')],
      ],
    ]);
    $page->save();
    $added = $this->indexBundles($bundles);
    $this->publisher->publish($page, TRUE);
    $placed = $form_state->getValue('place_block') && $this->placeBlock($page, (string) $form_state->getValue('region'));

    $this->messenger()->addStatus($this->t('The search page is at <a href=":url">/@route</a>.', [
      ':url' => Url::fromUserInput('/' . $route)->toString(),
      '@route' => $route,
    ]));
    if ($added) {
      $this->messenger()->addWarning($this->t('Content types were added to the search index. <a href=":url">Re-index the content</a> so that the page finds them.', [
        ':url' => Url::fromRoute('quant_search.main.index')->toString(),
      ]));
    }
    if ($placed) {
      $this->messenger()->addWarning($this->t('The search box is on every page in Drupal now. The static site shows it after its pages are published again: <a href=":url">publish the site again</a> (Seed, then Save and Queue).', [
        ':url' => Url::fromRoute('quant.seed')->toString(),
      ]));
    }
    $form_state->setRedirect('entity.quant_search_page.collection');
  }

  /**
   * Makes the index cover the page's content types.
   *
   * In the index settings an empty bundle list means every content type.
   *
   * @return bool
   *   Whether the settings changed (the content must be re-indexed).
   */
  protected function indexBundles(array $bundles): bool {
    $settings = $this->configFactory()->getEditable('quant_search.entities.settings');
    $enabled = (bool) $settings->get('quant_search_entity_node');
    $current = array_keys(array_filter((array) $settings->get('quant_search_entity_node_bundles')));
    $covered = $current === [] || ($bundles !== [] && array_diff($bundles, $current) === []);
    if ($enabled && $covered) {
      return FALSE;
    }
    // Indexing was off: index what the page needs (empty is every type).
    // Indexing was on: add the page's types, or every type for a page that
    // searches every type.
    $new = match (TRUE) {
      !$enabled, $bundles === [] => $bundles,
      default => array_values(array_unique(array_merge($current, $bundles))),
    };
    $settings->set('quant_search_entity_node', TRUE)
      ->set('quant_search_entity_node_bundles', $new ? array_combine($new, $new) : [])
      ->save();
    return TRUE;
  }

  /**
   * Places the autocomplete block in the default theme.
   *
   * A block of the same ID that a deleted page left behind is pointed at this
   * page instead.
   *
   * @return bool
   *   Whether a block was placed or repaired.
   */
  protected function placeBlock($page, string $region): bool {
    $theme = $this->config('system.theme')->get('default');
    $storage = $this->entityTypeManager->getStorage('block');
    $id = $theme . '_quant_search_' . $page->id();
    $existing = $storage->load($id);
    if ($existing) {
      $settings = $existing->get('settings');
      if ($this->pageExists($settings['page'] ?? '')) {
        return FALSE;
      }
      $settings['page'] = $page->uuid();
      $existing->set('settings', $settings)->save();
      return TRUE;
    }
    $storage->create([
      'id' => $id,
      'theme' => $theme,
      'region' => $region,
      'plugin' => 'quant_autocomplete_block',
      'weight' => 10,
      'settings' => [
        'id' => 'quant_autocomplete_block',
        'label' => (string) $this->t('Search'),
        'label_display' => '0',
        'provider' => 'quant_search',
        'page' => $page->uuid(),
        'placeholder' => (string) $this->t('Search'),
        'show_summary' => FALSE,
      ],
      'visibility' => [],
    ])->save();
    return TRUE;
  }

  /**
   * Whether a search page with this UUID exists.
   */
  protected function pageExists(string $uuid): bool {
    return $uuid !== '' && (bool) $this->entityTypeManager->getStorage('quant_search_page')->loadByProperties(['uuid' => $uuid]);
  }

  /**
   * A path without slashes at either end.
   */
  public static function normaliseRoute(string $route): string {
    return strtolower(trim(trim($route), '/'));
  }

  /**
   * The entity id of a path: news/search becomes news_search.
   */
  public static function machineName(string $route): string {
    return substr(preg_replace('/[^a-z0-9_]+/', '_', $route), 0, 64);
  }

}
