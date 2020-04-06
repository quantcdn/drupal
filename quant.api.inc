<?php

/**
 * @file
 * Hooks defined by the quant module.
 */

/**
 * Invoked after the entity has been rendered
 *
 * This function will recieve the data that is to be
 * seeded to the storage interface.
 *
 * @param string $data
 *   String data to be stored.
 */
function hook_quant_seed($data) {
  // Send the data to the configured API.
}
