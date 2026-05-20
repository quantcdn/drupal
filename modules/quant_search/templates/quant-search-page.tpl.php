<?php
/**
 * @file
 * Quant search page shell. InstantSearch.js mounts widgets into these divs.
 *
 * Available variables:
 * - $page: the search page array.
 * - $facets: facets with 'facet_key' / 'facet_container'.
 * - $instance: the page machine name, used as an id prefix.
 */
?>
<div class="quant-search" id="quant-search-<?php print $instance; ?>">
  <?php if (!empty($page['description'])): ?>
    <div class="quant-search-description"><?php print check_plain($page['description']); ?></div>
  <?php endif; ?>
  <div class="quant-search-grid">
    <?php if (!empty($facets)): ?>
      <div class="quant-search-facets">
        <?php if (!empty($page['display']['results']['show_clear_refinements'])): ?>
          <div id="qs-<?php print $instance; ?>-clear"></div>
        <?php endif; ?>
        <?php foreach ($facets as $facet): ?>
          <div class="quant-search-facet">
            <div class="quant-search-facet-heading"><?php print check_plain(isset($facet['heading']) ? $facet['heading'] : ''); ?></div>
            <div id="qs-<?php print $instance; ?>-facet-<?php print $facet['facet_container']; ?>"></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div class="quant-search-results">
      <?php if (!empty($page['display']['results']['display_search'])): ?>
        <div id="qs-<?php print $instance; ?>-searchbox"></div>
      <?php endif; ?>
      <?php if (!empty($page['display']['results']['display_stats'])): ?>
        <div id="qs-<?php print $instance; ?>-stats"></div>
      <?php endif; ?>
      <div id="qs-<?php print $instance; ?>-hits"></div>
      <?php if (!empty($page['display']['pagination']['pagination_enabled'])): ?>
        <div id="qs-<?php print $instance; ?>-pagination"></div>
      <?php endif; ?>
    </div>
  </div>
</div>
