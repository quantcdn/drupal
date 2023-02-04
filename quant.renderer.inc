<?php

/**
 * @file
 * Renderer functions for viewing nodes.
 */

/**
 * Render the title.
 *
 * @TODO: Geared toward nodes for MVP, this should be
 * extended to use the entity API.
 *
 * @return string
 *   The node title.
 */
function quant_page_title($node) {
  return $node->title;
}

/**
 * Access callback.
 *
 * In case we need to add some additional permissions to the node
 * view.
 *
 * @TODO: This could potentially use entity access for view as
 * anonymous users should have access to that but for now lets
 * assume not and return TRUE always.
 *
 * @return bool
 *   If the user has access.
 */
function quant_page_access($node) {
  return TRUE;
}

/**
 * Render the page.
 *
 * @TODO: Geared toward node for MVP, this should be extended
 * to use the entity API for more complete rendering.
 *
 * @return string
 *   HTML markup.
 */
function quant_page_view($node) {
  $uri = entity_uri('node', $node);

  drupal_add_html_head_link(array(
    'rel' => 'canonical',
    'href' => url($uri['path'], $uri['options']),
  ), TRUE);

  $nodes = node_view_multiple(array(
    $node->nid => $node,
  ));

  return $nodes;
}
