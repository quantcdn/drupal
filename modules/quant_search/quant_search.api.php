<?php

/**
 * @file
 * Hooks provided by the Quant Search module.
 */

/**
 * Alter the per-instance JS settings before they reach the browser.
 *
 * Invoked from quant_search_render() once per rendered search page (route or
 * block). Lets themes and modules mutate any aspect of the settings object —
 * credentials, facet config, filter string, display options.
 *
 * @param array &$settings
 *   The per-instance settings array. Keys include:
 *   - instance:  machine name of the search page (string).
 *   - app_id:    search backend application id.
 *   - read_key:  read-only search api key.
 *   - index:     search index name.
 *   - filters:   pre-built backend filter string.
 *   - facets:    list of facet definitions, each augmented with facet_key /
 *                facet_container by quant_search_compute_facet_keys().
 *   - display:   results / pagination / layout / attached_js / attached_css.
 * @param array $page
 *   The loaded quant_search_page record (read-only context).
 *
 * Example: flip a facet's widget on a specific page.
 * @code
 * function mymodule_quant_search_settings_alter(&$settings, $page) {
 *   if ($page['machine_name'] === 'whats_on_qs') {
 *     foreach ($settings['facets'] as &$facet) {
 *       if ($facet['facet_key'] === 'event_type_en') {
 *         $facet['widget'] = 'pills';
 *       }
 *     }
 *   }
 * }
 * @endcode
 */
function hook_quant_search_settings_alter(array &$settings, array $page) {
}
