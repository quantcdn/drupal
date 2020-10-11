<?php

/**
 * @file
 * Hooks defined by the quant module.
 */

/**
 * Invoked after the entity has been rendered.
 *
 * This function will recieve the data that is to be
 * seeded to the storage interface.
 *
 * @param string $location
 *   The location the file should be stored.
 * @param string $data
 *   String data to be stored.
 */
function hook_quant_seed($location, $data) {
  // Send the data to the configured API.
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
function hook_quant_meta_alter($meta) {
  // Change add to the meta array.
}
