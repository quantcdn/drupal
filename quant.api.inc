<?php

/**
 * @file
 * Hooks defined by the Quant module.
 */

/**
 * Add items to the quant_queue.
 *
 * This hook is called when Quant is gathering the routes
 * that it needs to send to the hosted service. This will
 * allow a module to hook into the collection process and
 * provide data for the seed process.
 *
 * Any items added to the Quant queue need to follow the
 * same structure as a batch operation; an array that has
 * callback and arguments as each item will be executed
 * with call_user_func_array.
 */
function hook_quant_seed_queue() {
  $queue = quant_get_queue();
  $item = array('callback', array('context'));
  $queue->createItem($item);
}

/**
 * Invoked after the entity has been rendered.
 *
 * This function will receive the data that is to be
 * seeded to the storage interface.
 *
 * @param string $location
 *   The location the file should be stored.
 * @param string $data
 *   String data to be stored.
 * @param array $meta
 *   The collected metadata about the route.
 * @param array $context
 *   The entity context for the route.
 */
// phpcs:ignore
function hook_quant_seed($location, $data, $meta, $context) {
  // Send the data to the configured API.
}

/**
 * Invoked after the entity has been rendered.
 *
 * This function will receive the data that is to be
 * seeded to the storage interface.
 *
 * @param string $location
 *   The location the file should be stored.
 * @param array $context
 *   The entity context for the route.
 */
// phpcs:ignore
function hook_quant_seed_file($url, $context) {
  // Send the data to the configured API.
}

/**
 * Provide additional data about for a route.
 *
 * Allows modules to hook in and change or add to
 * the core provided meta data for Quant files.
 *
 * @param array $meta
 *   The metadata array.
 */
// phpcs:ignore
function hook_quant_meta_alter($meta) {
  // Change add to the meta array.
}

/**
 * Targeted meta provider.
 *
 * Allows modules to hook in and change or add to
 * the core provided meta data for Quant files.
 *
 * @param array $meta
 *   The metadata array.
 */
// phpcs:ignore
function hook_quant_meta_TYPE_alter($meta) {
  // Change add to the meta array.
}

/**
 * Provide additional data about a file.
 *
 * Allows modules to hook in and change or add to
 * the core provided meta data for Quant files.
 *
 * @param array $meta
 *   The metadata array.
 */
// phpcs:ignore
function hook_quant_file_meta_alter(&$meta, $context) {
  // Change add meta specific to files.
}
