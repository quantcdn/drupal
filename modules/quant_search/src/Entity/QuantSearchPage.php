<?php

namespace Drupal\quant_search\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\quant_search\QuantSearchPageInterface;

/**
 * Defines the quant_search_page entity type.
 *
 * @ConfigEntityType(
 *   id = "quant_search_page",
 *   label = @Translation("Quant Search Page"),
 *   label_collection = @Translation("Quant Search Pages"),
 *   label_singular = @Translation("Quant Search Page"),
 *   label_plural = @Translation("Quant Search Pages"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Quant Search Page",
 *     plural = "@count Quant Search Pages",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\quant_search\QuantSearchPageListBuilder",
 *     "form" = {
 *       "add" = "Drupal\quant_search\Form\QuantSearchPageForm",
 *       "edit" = "Drupal\quant_search\Form\QuantSearchPageForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "quant_search",
 *   admin_permission = "administer quant search",
 *   links = {
 *     "collection" = "/admin/config/development/quant/search/pages",
 *     "add-form" = "/admin/config/development/quant/search/pages/add",
 *     "edit-form" = "/admin/config/development/quant/search/pages/{quant_search_page}",
 *     "delete-form" = "/admin/config/development/quant/search/pages/{quant_search_page}/delete"
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "route" = "route",
 *     "title" = "title",
 *     "description" = "description",
 *     "languages" = "languages",
 *     "bundles" = "bundles",
 *     "manual_filters" = "manual_filters",
 *     "facets" = "facets",
 *     "display" = "display"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "route",
 *     "title",
 *     "description",
 *     "languages",
 *     "bundles",
 *     "manual_filters",
 *     "facets",
 *     "display"
 *   }
 * )
 */
class QuantSearchPage extends ConfigEntityBase implements QuantSearchPageInterface {

  /**
   * The quant_search_page ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The quant_search_page label.
   *
   * @var string
   */
  protected $label;

  /**
   * The quant_search_page title.
   *
   * @var string
   */
  protected $title;

  /**
   * The quant_search_page status.
   *
   * @var bool
   */
  protected $status;

  /**
   * The quant_search_page description.
   *
   * @var string
   */
  protected $description;

  /**
   * The quant_search_page route.
   *
   * @var string
   */
  protected $route;

  /**
   * The languages to filter.
   *
   * @var array
   */
  protected $languages = [];

  /**
   * The bundles to filter.
   *
   * @var array
   */
  protected $bundles = [];

  /**
   * Manual filter string.
   *
   * @var string
   */
  protected $manual_filters;

  /**
   * Facets.
   *
   * @var array
   */
  protected $facets = [];

  /**
   * Display configuration.
   *
   * @var array
   */
  protected $display = [];

}
