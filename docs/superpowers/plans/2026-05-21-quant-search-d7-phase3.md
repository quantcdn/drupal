# D7 quant_search Phase 3 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the D7 `quant_search` module customisable by sites without changing module code — overridable JS hit renderer, PHP settings-alter hook, per-page attached-asset config — and add `radio` and `pills` facet widget modes.

**Architecture:** Refactor the in-JS hit template into a global `Drupal.quantSearch.renderHit(hit, cfg)` whose default is byte-for-byte equivalent to today's output. Add two new facet widget cases (`radio` over `connectMenu`, `pills` over `refinementList` with a CSS class). Add `drupal_alter('quant_search_settings', $settings, $page)` and ship a `quant_search.api.php` documenting `hook_quant_search_settings_alter`. Add two textareas to the search-page admin form (`attached_js`, `attached_css`) and feed them through `#attached` at render time.

**Tech Stack:** Drupal 7, PHP, vanilla JS (no transpilation), Algolia-compatible InstantSearch.js v4, plain CSS.

---

## Context for the implementer

- **Worktree:** `/Users/stuart/apps/quant-drupal/.worktrees/quant-search-d7/`. The branch entering Phase 3 is `feat/quant-search-d7` (just pushed). **Phase 3 work belongs on a new branch `feat/quant-search-d7-phase3` branched off `feat/quant-search-d7`** — create it at the very start of Task 1.
- **Design spec:** `docs/superpowers/specs/2026-05-21-quant-search-d7-phase3-design.md`. Read it first.
- **Test site:** Drupal 7 at `/Users/stuart/apps/slv-migration/www-slv-qc/` (http://localhost:8084). The worktree's `modules/quant_search/` is bind-mounted into the container at `/opt/drupal/sites/all/modules/contrib/quantcdn/modules/quant_search/`, so edits land live. Run drush with `cd /Users/stuart/apps/slv-migration/www-slv-qc && docker compose exec -T -w /opt/drupal drupal drush <cmd>`.
- The existing `whats_on_qs` search page (route `whats-on-qs`) is configured with one taxonomy facet on `event_type` and one date-range facet on `field_event_session`. It has 200 events indexed.
- **JS aggregation is currently OFF** on the dev site (`preprocess_js=0`, `preprocess_css=0`) so iterative changes show up. If you need to bump the cache buster: `docker compose exec -T -w /opt/drupal drupal drush ev '_drupal_flush_css_js();'`.
- Git author identity is already correct via the local config (`Stuart Rowlands <stuart.rowlands@quantcdn.io>`). **Use plain `git commit -m "..."` — do NOT pass `-c user.name=Claude -c user.email=...`.**
- This is procedural D7 + vanilla JS; "tests" are verification commands (`php -l`, `node --check`, drush queries, browser-DOM probes via the same `docker compose exec -T -w /opt/drupal drupal drush ev` approach used in earlier phases).

---

## File structure

| Path | Change |
|------|--------|
| `modules/quant_search/quant_search.pages.inc` | Add `radio` + `pills` to the widget options; add `attached_js` + `attached_css` textareas; persist + unpack the two new keys |
| `modules/quant_search/quant_search.theme.inc` | `drupal_alter('quant_search_settings', $instance_settings, $page)` invocation; iterate `display.attached_js` / `display.attached_css` into the render array's `#attached` |
| `modules/quant_search/quant_search.api.php` *(new)* | Documentation file containing `hook_quant_search_settings_alter` example |
| `modules/quant_search/js/quant-search.js` | Refactor hits template into `Drupal.quantSearch.renderHit`; add `radio` widget helper + case; add `pills` widget case via `refinementList` `cssClasses.root` |
| `modules/quant_search/css/quant-search.css` | `.quant-search--widget-pills` + `.quant-search--widget-radio` styles |
| `modules/quant_search/README.md` | New "Customisation" section: three extension points + one SLV-style `renderHit` example |

---

### Task 1: Branch + refactor hits template into `Drupal.quantSearch.renderHit`

**Files:**
- Modify: `modules/quant_search/js/quant-search.js`

This task creates the branch, then refactors the hits widget's `item` template to call a new module-global `Drupal.quantSearch.renderHit(hit, cfg)`. The default implementation reproduces today's output exactly. To let the default reach the date-facet state without closure access (since `renderHit` is global), we attach the closure-scoped `dateFacetKeys` and `dateRefinements` onto `cfg` as `cfg._dateFacetKeys` and `cfg._dateRefinements`. The underscore prefix signals "managed by the module — site overrides may read but should not mutate".

- [ ] **Step 1: Create the Phase 3 branch**

```bash
cd /Users/stuart/apps/quant-drupal/.worktrees/quant-search-d7
git checkout -b feat/quant-search-d7-phase3
git branch --show-current
```

Expected: `feat/quant-search-d7-phase3`.

- [ ] **Step 2: Add the closure stash inside `build()`**

In `modules/quant_search/js/quant-search.js`, find the block that declares `dateRefinements` and `dateFacetKeys`:

```js
    // Track active date-range refinements for use in the hits template.
    var dateRefinements = {};
    var dateFacetKeys = (cfg.facets || []).filter(function (f) {
      return f.widget === 'date' || f.type === 'date_range';
    }).map(function (f) { return f.facet_key; });
```

Immediately after that block, add:

```js
    // Expose the date-facet state to renderHit overrides via cfg.
    cfg._dateFacetKeys = dateFacetKeys;
    cfg._dateRefinements = dateRefinements;
```

- [ ] **Step 3: Replace the hits widget block to delegate to `renderHit`**

Find the hits widget block (the `widgets.push(instantsearch.widgets.hits({ ... }));` near the end of `build()`):

```js
    widgets.push(instantsearch.widgets.hits({
      container: id('hits'),
      templates: {
        empty: '<p>' + Drupal.t('No results found.') + '</p>',
        item: function (hit) {
          var url = Drupal.quantSearch.safeUrl(hit.url);
          var image = Drupal.quantSearch.safeUrl(hit.image);
          var img = image ? '<img src="' + image + '" alt="" class="qs-hit-image" onerror="this.hidden=true" />' : '';
          var summary = hit.summary
            ? '<div class="qs-hit-summary">' + Drupal.checkPlain(hit.summary) + '</div>' : '';
          var sessions = Drupal.quantSearch.formatSessionInfo(hit, dateFacetKeys, dateRefinements);
          return '<a class="qs-hit" href="' + url + '">' + img +
            '<h4 class="qs-hit-title">' + Drupal.checkPlain(hit.title || '') + '</h4>' +
            sessions + summary + '</a>';
        }
      }
    }));
```

Replace it with:

```js
    widgets.push(instantsearch.widgets.hits({
      container: id('hits'),
      templates: {
        empty: '<p>' + Drupal.t('No results found.') + '</p>',
        item: function (hit) { return Drupal.quantSearch.renderHit(hit, cfg); }
      }
    }));
```

- [ ] **Step 4: Add the default `Drupal.quantSearch.renderHit`**

Just below the `Drupal.quantSearch.formatSessionInfo = function (...) { ... };` definition (and above the date widget definition), add:

```js
  /**
   * Default hit renderer. Override Drupal.quantSearch.renderHit in site JS
   * (loaded after this module's JS) to customise card markup.
   *
   * The hit object carries every field the indexer wrote — including the
   * fields named in the per-entity `index_fields` config (e.g. field_cost,
   * field_booking_status_label). cfg._dateFacetKeys and cfg._dateRefinements
   * expose the active date-facet state for renderers that want to show
   * the in-range session count.
   *
   * @param {object} hit  The Algolia hit (every indexed field is available).
   * @param {object} cfg  The InstantSearch config for this instance.
   * @return {string}     HTML.
   */
  Drupal.quantSearch.renderHit = function (hit, cfg) {
    var url = Drupal.quantSearch.safeUrl(hit.url);
    var image = Drupal.quantSearch.safeUrl(hit.image);
    var img = image ? '<img src="' + image + '" alt="" class="qs-hit-image" onerror="this.hidden=true" />' : '';
    var summary = hit.summary
      ? '<div class="qs-hit-summary">' + Drupal.checkPlain(hit.summary) + '</div>' : '';
    var sessions = Drupal.quantSearch.formatSessionInfo(
      hit, cfg._dateFacetKeys || [], cfg._dateRefinements || {}
    );
    return '<a class="qs-hit" href="' + url + '">' + img +
      '<h4 class="qs-hit-title">' + Drupal.checkPlain(hit.title || '') + '</h4>' +
      sessions + summary + '</a>';
  };
```

- [ ] **Step 5: Verify syntax + the default renders the same as before**

```bash
node --check modules/quant_search/js/quant-search.js
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush ev '_drupal_flush_css_js();' 2>&1
docker compose exec -T -w /opt/drupal drupal drush cc all
```

Then in a browser (or via playwright) load http://localhost:8084/whats-on-qs and confirm:

- The page still renders 12 hit cards.
- Each card still shows title, session badge ("4 sessions · next 13 May 2026" for Baby Bounce, etc.), and the broken-image placeholder hides via onerror.
- Apply the date-range facet (e.g. May 1 – May 31 2026) and confirm the session counts update (Baby Bounce → "4 sessions · from 13 May 2026" etc.).

If any visual difference from the prior state appears, STOP and report BLOCKED.

- [ ] **Step 6: Commit**

```bash
git add modules/quant_search/js/quant-search.js
git commit -m "feat(quant_search): refactor hit template into Drupal.quantSearch.renderHit

Sites can now override Drupal.quantSearch.renderHit in their own JS to
customise card markup without modifying the module. The default
implementation is byte-for-byte equivalent to the prior in-line template.

The active date-facet state is exposed on cfg as cfg._dateFacetKeys and
cfg._dateRefinements so overrides can replicate the session-count badge."
```

---

### Task 2: Add `radio` facet widget

**Files:**
- Modify: `modules/quant_search/quant_search.pages.inc`
- Modify: `modules/quant_search/js/quant-search.js`
- Modify: `modules/quant_search/css/quant-search.css`

Single-select facet that emits radio inputs with an "All" option that clears the refinement. Built on InstantSearch's `connectMenu` connector (the standard single-select primitive).

- [ ] **Step 1: Add `radio` to the widget options in the admin form**

In `modules/quant_search/quant_search.pages.inc`, find `quant_search_page_facet_rows()` — the `$widget_options` array:

```php
  $widget_options = array(
    'checkbox' => t('Checkbox list'),
    'select' => t('Select dropdown'),
    'menu' => t('Menu'),
    'date' => t('Date range inputs'),
  );
```

Replace with:

```php
  $widget_options = array(
    'checkbox' => t('Checkbox list'),
    'select' => t('Select dropdown'),
    'menu' => t('Menu'),
    'radio' => t('Radio buttons (single-select)'),
    'pills' => t('Pill buttons (multi-select)'),
    'date' => t('Date range inputs'),
  );
```

(Both `radio` and `pills` are added here in one shot; Task 3 implements pills on the JS/CSS side.)

- [ ] **Step 2: Add the `radio` case in the JS facet switch**

In `modules/quant_search/js/quant-search.js`, find the facet switch inside `build()`:

```js
        case 'date':
          (function (key) {
            widgets.push(Drupal.quantSearch.dateRangeWidget(container, key, function (min, max) {
              if (min === undefined && max === undefined) {
                delete dateRefinements[key];
              } else {
                dateRefinements[key] = { min: min, max: max };
              }
            }));
          }(facet.facet_key));
          break;
```

Immediately before the `case 'date':` line, add:

```js
        case 'radio':
          widgets.push(Drupal.quantSearch.radioWidget(container, facet.facet_key, facet.limit || 10));
          break;
```

- [ ] **Step 3: Add the `radioWidget` helper**

Below `Drupal.quantSearch.dateRangeWidget` (or near other public widget helpers — same area), add:

```js
  /**
   * Single-select facet widget rendered as radio inputs with an "All" option.
   *
   * Backed by InstantSearch's connectMenu connector.
   */
  Drupal.quantSearch.radioWidget = function (container, attribute, limit) {
    var name = 'qs-radio-' + attribute;
    var render = function (renderOptions, isFirstRender) {
      var node = document.querySelector(container);
      if (!node) { return; }
      if (isFirstRender) {
        node.classList.add('quant-search--widget-radio');
      }
      var hasSelection = renderOptions.items.some(function (i) { return i.isRefined; });
      var html = '<label class="qs-radio-item"><input type="radio" name="' + name + '" value=""' +
                 (hasSelection ? '' : ' checked') + '> ' + Drupal.t('All') + '</label>';
      renderOptions.items.forEach(function (item) {
        html += '<label class="qs-radio-item"><input type="radio" name="' + name + '" value="' +
                Drupal.checkPlain(item.value) + '"' +
                (item.isRefined ? ' checked' : '') + '> ' +
                Drupal.checkPlain(item.label) + ' <span class="qs-radio-count">(' + item.count + ')</span></label>';
      });
      node.innerHTML = html;
      Array.from(node.querySelectorAll('input[type="radio"]')).forEach(function (input) {
        input.addEventListener('change', function (e) {
          var val = e.target.value;
          if (val === '') {
            renderOptions.items.forEach(function (item) {
              if (item.isRefined) { renderOptions.refine(item.value); }
            });
          } else {
            renderOptions.refine(val);
          }
        });
      });
    };
    return instantsearch.connectors.connectMenu(render)({ attribute: attribute, limit: limit });
  };
```

- [ ] **Step 4: Add radio CSS**

In `modules/quant_search/css/quant-search.css`, append at the end:

```css
/* Radio widget — single-select facet. */
.quant-search--widget-radio { display: flex; flex-direction: column; gap: .35rem; }
.quant-search--widget-radio .qs-radio-item { display: flex; align-items: center; gap: .5rem; cursor: pointer; font-size: .95em; }
.quant-search--widget-radio .qs-radio-count { color: #888; font-size: .9em; }
```

- [ ] **Step 5: Verify the radio facet works against `field_paid_free`**

Add a radio facet to the `whats_on_qs` search page on `field_paid_free` and re-register facets:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush ev '
$p = quant_search_page_load_by_name("whats_on_qs");
$facets = $p["facets"];
// Add a radio facet on field_paid_free if not already present.
$has = false;
foreach ($facets as $f) { if (!empty($f["custom_key"]) && $f["custom_key"] === "field_paid_free") { $has = true; break; } }
if (!$has) {
  $facets[] = array(
    "heading" => "Cost",
    "type" => "custom",
    "vocabulary" => "",
    "date_field" => "",
    "custom_key" => "field_paid_free",
    "widget" => "radio",
    "language" => "en",
    "limit" => 5,
    "weight" => -5,
  );
}
$p["facets"] = $facets;
quant_search_page_save($p);
module_load_include("inc","quant_search","quant_search.api");
module_load_include("inc","quant_search","quant_search.theme");
$keys = array();
foreach (quant_search_compute_facet_keys($facets) as $f) { $keys[] = $f["facet_key"]; }
quant_search_api_add_facets($keys);
echo "ok\n";'
docker compose exec -T -w /opt/drupal drupal drush ev '_drupal_flush_css_js();'
docker compose exec -T -w /opt/drupal drupal drush cc all
```

Then load http://localhost:8084/whats-on-qs?nocache=2 in a browser. Confirm:

- A "Cost" facet appears with an "All" radio plus a radio per value (`free`, `paid`).
- Selecting "Paid" narrows the hit count.
- Selecting "All" restores the full result set.

If the radio inputs don't render or don't refine, STOP and report BLOCKED with the actual DOM state.

- [ ] **Step 6: Commit**

```bash
git add modules/quant_search/quant_search.pages.inc \
        modules/quant_search/js/quant-search.js \
        modules/quant_search/css/quant-search.css
git commit -m "feat(quant_search): add radio facet widget mode

Single-select facet rendered as a radio group with an 'All' option that
clears the refinement. Built on connectMenu; suits cost / status-style
two-or-three-value lists."
```

---

### Task 3: Add `pills` facet widget

**Files:**
- Modify: `modules/quant_search/js/quant-search.js`
- Modify: `modules/quant_search/css/quant-search.css`

`pills` is a styling variant of the existing `checkbox` widget (multi-select) — same `refinementList` connector, with a `cssClasses.root` hook on the wrapper, hidden checkbox input, and pill-shaped labels.

- [ ] **Step 1: Add the `pills` case in the JS facet switch**

In `modules/quant_search/js/quant-search.js`, find the `case 'checkbox':` block:

```js
        case 'checkbox':
          widgets.push(instantsearch.widgets.refinementList({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;
```

Immediately after that `break;`, add:

```js
        case 'pills':
          widgets.push(instantsearch.widgets.refinementList({
            container: container,
            attribute: facet.facet_key,
            limit: facet.limit || 10,
            cssClasses: { root: 'quant-search--widget-pills' }
          }));
          break;
```

- [ ] **Step 2: Add pills CSS**

In `modules/quant_search/css/quant-search.css`, append at the end:

```css
/* Pills widget — multi-select facet styled as toggle buttons. */
.quant-search--widget-pills .ais-RefinementList-list { display: flex; flex-wrap: wrap; gap: .5rem; list-style: none; padding: 0; margin: 0; }
.quant-search--widget-pills .ais-RefinementList-item { margin: 0; padding: 0; }
.quant-search--widget-pills .ais-RefinementList-label { display: inline-flex; align-items: center; gap: .35rem; padding: .35rem .9rem; border: 1px solid #ccc; border-radius: 9999px; background: #fff; cursor: pointer; font-size: .9em; line-height: 1.2; }
.quant-search--widget-pills .ais-RefinementList-checkbox { position: absolute; opacity: 0; pointer-events: none; }
.quant-search--widget-pills .ais-RefinementList-item--selected .ais-RefinementList-label,
.quant-search--widget-pills .ais-RefinementList-label:has(input:checked) { background: #333; color: #fff; border-color: #333; }
.quant-search--widget-pills .ais-RefinementList-count { color: inherit; opacity: .7; font-size: .85em; }
```

- [ ] **Step 3: Verify against the existing `event_type` taxonomy facet**

Switch the existing taxonomy facet's widget to `pills`:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush ev '
$p = quant_search_page_load_by_name("whats_on_qs");
foreach ($p["facets"] as &$f) {
  if (!empty($f["vocabulary"]) && $f["vocabulary"] === "event_type") { $f["widget"] = "pills"; }
}
unset($f);
quant_search_page_save($p);
echo "ok\n";'
docker compose exec -T -w /opt/drupal drupal drush ev '_drupal_flush_css_js();'
docker compose exec -T -w /opt/drupal drupal drush cc all
```

Load http://localhost:8084/whats-on-qs?nocache=3 and confirm:

- The event-type facet now renders as a horizontal row of pill-shaped buttons (10 categories: Talks & Ideas, Kids & families, Exhibitions, etc.).
- Clicking a pill toggles selection (visible state: dark fill); the result count updates.
- Multiple pills can be selected at once.

If the pills don't visually appear as buttons (e.g. they still look like a checkbox list), inspect the DOM and confirm the wrapper has the `quant-search--widget-pills` class; if not, the `cssClasses.root` option isn't being applied — STOP and report with the actual class names.

- [ ] **Step 4: Commit**

```bash
git add modules/quant_search/js/quant-search.js modules/quant_search/css/quant-search.css
git commit -m "feat(quant_search): add pills facet widget mode

Multi-select facet styled as horizontal toggle buttons. Same
refinementList semantics as the checkbox widget; just a cssClasses.root
hook and CSS variant — no new connector."
```

---

### Task 4: `hook_quant_search_settings_alter`

**Files:**
- Modify: `modules/quant_search/quant_search.theme.inc`
- Create: `modules/quant_search/quant_search.api.php`

PHP-side extension point invoked once per render, just before the per-instance settings object ships to `Drupal.settings`. Receives the inner instance settings (not the wrapper) so hook implementations don't have to dig.

- [ ] **Step 1: Invoke `drupal_alter` in `quant_search_render()`**

In `modules/quant_search/quant_search.theme.inc`, find the section in `quant_search_render($page)` that builds the `$settings` array. The current code looks like:

```php
  $settings = array(
    'quantSearch' => array(
      $instance => array(
        'instance' => $instance,
        'app_id' => $index->algolia_application_id,
        'read_key' => $index->algolia_read_key,
        'index' => $index->algolia_index,
        'filters' => quant_search_build_filters($page),
        'facets' => $facets,
        'display' => $page['display'],
      ),
    ),
  );
```

Immediately after that `$settings = array(...);` block, add:

```php
  // Let other modules / themes mutate the instance settings before they
  // ship to the browser. The reference is to the inner per-instance array
  // so implementations don't have to dig through the wrapper.
  drupal_alter('quant_search_settings', $settings['quantSearch'][$instance], $page);
```

- [ ] **Step 2: Write `quant_search.api.php` documenting the hook**

Create `modules/quant_search/quant_search.api.php` with:

```php
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
```

- [ ] **Step 3: Verify the hook fires**

Create a tiny throw-away module on the test site to assert the hook is reachable:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T drupal sh -c '
mkdir -p /opt/drupal/sites/all/modules/custom/qs_alter_test
cat > /opt/drupal/sites/all/modules/custom/qs_alter_test/qs_alter_test.info <<EOF
name = QS Alter Test
core = 7.x
package = Quant
EOF
cat > /opt/drupal/sites/all/modules/custom/qs_alter_test/qs_alter_test.module <<EOF
<?php
function qs_alter_test_quant_search_settings_alter(&\$settings, \$page) {
  variable_set("qs_alter_test_fired", \$page["machine_name"]);
  \$settings["display"]["layout"] = "list";
}
EOF
'
docker compose exec -T -w /opt/drupal drupal drush en qs_alter_test -y
docker compose exec -T -w /opt/drupal drupal drush vdel qs_alter_test_fired -y 2>&1 | tail -3
# Render the page once and see if the variable gets set + the layout flipped.
docker compose exec -T -w /opt/drupal drupal drush ev '
module_load_include("inc","quant_search","quant_search.pages");
module_load_include("inc","quant_search","quant_search.theme");
$p = quant_search_page_load_by_name("whats_on_qs");
$out = quant_search_render($p);
echo "fired for: " . variable_get("qs_alter_test_fired", "(none)") . "\n";
$found_layout = NULL;
foreach (($out["#attached"]["js"] ?? array()) as $a) {
  if (is_array($a) && isset($a["type"]) && $a["type"] === "setting" && isset($a["data"]["quantSearch"]["whats_on_qs"])) {
    $found_layout = $a["data"]["quantSearch"]["whats_on_qs"]["display"]["layout"] ?? "(unset)";
  }
}
echo "layout in settings: " . var_export($found_layout, true) . "\n";'
# Teardown
docker compose exec -T -w /opt/drupal drupal drush dis qs_alter_test -y 2>&1 | tail -2
docker compose exec -T -w /opt/drupal drupal drush ev '
module_load_include("module","system","system");
$schema = drupal_get_schema("system");
db_delete("system")->condition("name","qs_alter_test")->execute();'
docker compose exec -T drupal rm -rf /opt/drupal/sites/all/modules/custom/qs_alter_test
docker compose exec -T -w /opt/drupal drupal drush cc all
```

Expected: `fired for: whats_on_qs` and `layout in settings: 'list'`.

- [ ] **Step 4: Commit**

```bash
git add modules/quant_search/quant_search.theme.inc modules/quant_search/quant_search.api.php
git commit -m "feat(quant_search): add hook_quant_search_settings_alter

Themes and modules can mutate the per-instance JS settings before they
ship to the browser. The inner per-instance array is passed by
reference so hook implementations don't have to dig through the
wrapper. Example: flipping a facet's widget on a specific page."
```

---

### Task 5: Per-page `attached_js` / `attached_css`

**Files:**
- Modify: `modules/quant_search/quant_search.pages.inc`
- Modify: `modules/quant_search/quant_search.theme.inc`

Two textareas on the search-page admin form, stored in the `display` config blob, processed through `#attached.js` / `#attached.css` at render time. External URLs (starting with `http://` or `https://`) are added with `type=external`; everything else is treated as a path.

- [ ] **Step 1: Add the two textareas to the search-page admin form**

In `modules/quant_search/quant_search.pages.inc`, find the `display` fieldset block in `quant_search_page_form()` — specifically the `layout` element added in Phase 2:

```php
  $form['display']['layout'] = array(
    '#type' => 'select',
    '#title' => t('Default results layout'),
    '#options' => array(
      'card' => t('Cards'),
      'list' => t('List'),
    ),
    '#default_value' => isset($d['layout']) ? $d['layout'] : 'card',
    '#description' => t('Visitors can switch on the page; this sets the initial layout.'),
  );
```

Immediately after that block, add:

```php
  $form['display']['attached_js'] = array(
    '#type' => 'textarea',
    '#title' => t('Additional JS to attach'),
    '#description' => t('One path or URL per line. External URLs (http/https) are detected automatically; everything else is treated as a path relative to the Drupal root.'),
    '#default_value' => isset($d['attached_js']) ? $d['attached_js'] : '',
    '#rows' => 3,
  );
  $form['display']['attached_css'] = array(
    '#type' => 'textarea',
    '#title' => t('Additional CSS to attach'),
    '#description' => t('One path or URL per line.'),
    '#default_value' => isset($d['attached_css']) ? $d['attached_css'] : '',
    '#rows' => 3,
  );
```

- [ ] **Step 2: Persist the two values in the submit handler**

In `quant_search_page_form_submit()`, find the `'display' => array(...)` block in the `$page` array:

```php
    'display' => array(
      'layout' => $v['display']['layout'],
      'results' => array(
        'display_search' => $v['display']['display_search'],
        'display_stats' => $v['display']['display_stats'],
        'show_clear_refinements' => $v['display']['show_clear_refinements'],
      ),
      'pagination' => array(
        'pagination_enabled' => $v['display']['pagination_enabled'],
        'per_page' => (int) $v['display']['per_page'],
      ),
    ),
```

Replace with:

```php
    'display' => array(
      'layout' => $v['display']['layout'],
      'attached_js' => trim($v['display']['attached_js']),
      'attached_css' => trim($v['display']['attached_css']),
      'results' => array(
        'display_search' => $v['display']['display_search'],
        'display_stats' => $v['display']['display_stats'],
        'show_clear_refinements' => $v['display']['show_clear_refinements'],
      ),
      'pagination' => array(
        'pagination_enabled' => $v['display']['pagination_enabled'],
        'per_page' => (int) $v['display']['per_page'],
      ),
    ),
```

- [ ] **Step 3: Add defaults in the unpack helper**

In `quant_search_page_unpack()`, find the nested-defaults block:

```php
  $config['display'] += array(
    'layout' => 'card',
    'results' => array(),
    'pagination' => array(),
  );
```

Replace with:

```php
  $config['display'] += array(
    'layout' => 'card',
    'attached_js' => '',
    'attached_css' => '',
    'results' => array(),
    'pagination' => array(),
  );
```

- [ ] **Step 4: Attach the assets at render time**

In `modules/quant_search/quant_search.theme.inc`, find the existing `#attached.js` array inside `quant_search_render()`:

```php
      'js' => array(
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/algoliasearch@4/dist/algoliasearch-lite.umd.js'),
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.js@4'),
        $module_path . '/js/quant-search.js',
        array('type' => 'setting', 'data' => $settings),
      ),
```

Replace the `return array(...)` block (the entire `'#attached' => array(...)` section) with:

```php
  $build = array(
    '#theme' => 'quant_search_page',
    '#page' => $page,
    '#facets' => $facets,
    '#instance' => $instance,
    '#attached' => array(
      'js' => array(
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/algoliasearch@4/dist/algoliasearch-lite.umd.js'),
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.js@4'),
        $module_path . '/js/quant-search.js',
        array('type' => 'setting', 'data' => $settings),
      ),
      'css' => array(
        $module_path . '/css/quant-search.css',
        array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/instantsearch.css@7/themes/algolia-min.css'),
      ),
    ),
  );

  // Per-page attached assets — paths or external URLs, one per line.
  foreach (quant_search_split_attached($page['display']['attached_js']) as $entry) {
    $build['#attached']['js'][] = $entry;
  }
  foreach (quant_search_split_attached($page['display']['attached_css']) as $entry) {
    $build['#attached']['css'][] = $entry;
  }

  return $build;
```

…which means you need to REMOVE the existing `return array('#theme' => 'quant_search_page', ...);` block above and replace it with the new `$build = array(...)` + foreach + return. Make sure to delete the duplicate render-array building.

Then add the helper near the top of the file (above `quant_search_compute_facet_keys`):

```php
/**
 * Splits an attached-assets textarea value into #attached entries.
 *
 * One path or URL per line. URLs starting with http(s):// are tagged as
 * 'external'; everything else passes through as a path string (D7's
 * #attached treats bare strings as files).
 *
 * @param string $raw
 *   Raw textarea value.
 *
 * @return array
 *   List of entries suitable for $build['#attached']['js' or 'css'].
 */
function quant_search_split_attached($raw) {
  $out = array();
  if (!is_string($raw) || $raw === '') {
    return $out;
  }
  foreach (preg_split('/\R+/', $raw) as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (preg_match('#^https?://#i', $line)) {
      $out[] = array('type' => 'external', 'data' => $line);
    }
    else {
      $out[] = $line;
    }
  }
  return $out;
}
```

- [ ] **Step 5: Verify the assets actually attach**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush ev '
$p = quant_search_page_load_by_name("whats_on_qs");
$p["display"]["attached_js"] = "https://example.com/never-loaded.js";
$p["display"]["attached_css"] = "https://example.com/never-loaded.css";
quant_search_page_save($p);
echo "saved\n";'
docker compose exec -T -w /opt/drupal drupal drush ev '_drupal_flush_css_js();'
docker compose exec -T -w /opt/drupal drupal drush cc all
curl -s "http://localhost:8084/whats-on-qs?probe=1" | grep -E "example\.com/never-loaded\.(js|css)" | head -2
```

Expected: two grep hits — the JS and CSS URLs appear in the rendered HTML. Roll back the test values:

```bash
docker compose exec -T -w /opt/drupal drupal drush ev '
$p = quant_search_page_load_by_name("whats_on_qs");
$p["display"]["attached_js"] = "";
$p["display"]["attached_css"] = "";
quant_search_page_save($p);
echo "rolled back\n";'
```

- [ ] **Step 6: Commit**

```bash
git add modules/quant_search/quant_search.pages.inc modules/quant_search/quant_search.theme.inc
git commit -m "feat(quant_search): per-page attached_js / attached_css

Two textareas on the search-page admin form — one URL or path per line.
External URLs are auto-tagged. Lets a site point at its own renderHit
override script and styling without writing a custom module."
```

---

### Task 6: README customisation section + final end-to-end verification

**Files:**
- Modify: `modules/quant_search/README.md`

- [ ] **Step 1: Append the customisation section to README.md**

After the existing "Known limitations" section in `modules/quant_search/README.md`, append:

```markdown
## Customisation

The module exposes three extension points so sites can customise behaviour
without modifying module code.

### 1. Hit card markup — `Drupal.quantSearch.renderHit`

Override the default hit renderer in your own JS (loaded after this module's
JS — use the "Additional JS to attach" textarea on the search page or attach
from your own module). Every indexed field on the hit is available; the active
date-facet state is on `cfg._dateFacetKeys` and `cfg._dateRefinements`.

```js
Drupal.quantSearch.renderHit = function (hit, cfg) {
  var url = Drupal.quantSearch.safeUrl(hit.url);
  var image = Drupal.quantSearch.safeUrl(hit.image);
  var img = image ? '<img src="' + image + '" alt="" class="qs-hit-image" onerror="this.hidden=true" />' : '';
  var soldOut = (hit.field_booking_status_label === 'Booked out')
    ? '<span class="qs-badge qs-badge--sold-out">' + Drupal.t('Sold Out') + '</span>'
    : '';
  var cost = hit.field_cost
    ? '<div class="qs-hit-cost">' + Drupal.t('Cost: ') + Drupal.checkPlain(hit.field_cost) + '</div>'
    : '';
  var sessions = Drupal.quantSearch.formatSessionInfo(
    hit, cfg._dateFacetKeys || [], cfg._dateRefinements || {}
  );
  return '<a class="qs-hit" href="' + url + '">' + img + soldOut +
    '<h4 class="qs-hit-title">' + Drupal.checkPlain(hit.title || '') + '</h4>' +
    sessions + cost + '</a>';
};
```

Other helpers available on `Drupal.quantSearch`: `safeUrl(url)`, `formatSessionInfo(hit, keys, refinements)`, `applyLayout(instance, layout)`, `dateRangeWidget(container, attribute, onChange)`, `radioWidget(container, attribute, limit)`.

### 2. Server-side settings mutation — `hook_quant_search_settings_alter`

Implement the hook from a custom module to mutate the per-instance JS
settings before they reach the browser. See `quant_search.api.php` for the
full hook signature.

```php
function mymodule_quant_search_settings_alter(array &$settings, array $page) {
  if ($page['machine_name'] === 'whats_on_qs') {
    foreach ($settings['facets'] as &$facet) {
      if ($facet['facet_key'] === 'event_type_en') {
        $facet['widget'] = 'pills';
      }
    }
  }
}
```

### 3. Per-page attached assets

The search-page admin form has two textareas — "Additional JS to attach" and
"Additional CSS to attach" — accepting one path or URL per line. Use them to
ship your `renderHit` override script and matching CSS without writing a
custom module.
```

- [ ] **Step 2: Final end-to-end verification**

Make sure the test page exercises every Phase 3 feature simultaneously:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush ev '
$p = quant_search_page_load_by_name("whats_on_qs");
// Ensure event_type uses pills + cost facet uses radio (set in tasks 2 + 3).
$has_radio = false;
foreach ($p["facets"] as $f) {
  if (!empty($f["widget"]) && $f["widget"] === "radio") { $has_radio = true; }
}
echo "radio facet: " . ($has_radio ? "yes" : "no") . "\n";
$has_pills = false;
foreach ($p["facets"] as $f) {
  if (!empty($f["widget"]) && $f["widget"] === "pills") { $has_pills = true; }
}
echo "pills facet: " . ($has_pills ? "yes" : "no") . "\n";
echo "attached_js default: " . var_export($p["display"]["attached_js"], true) . "\n";
echo "attached_css default: " . var_export($p["display"]["attached_css"], true) . "\n";'
docker compose exec -T -w /opt/drupal drupal drush ev '_drupal_flush_css_js();'
docker compose exec -T -w /opt/drupal drupal drush cc all
curl -s "http://localhost:8084/whats-on-qs?finalcheck=1" -o /tmp/whats-on-qs.html
echo "quant-search-page id: $(grep -o 'id=\"quant-search-whats_on_qs\"' /tmp/whats-on-qs.html | head -1)"
echo "renderHit references: $(curl -s 'http://localhost:8084/sites/all/modules/contrib/quantcdn/modules/quant_search/js/quant-search.js' | grep -c 'Drupal.quantSearch.renderHit')"
```

Expected: `radio facet: yes`, `pills facet: yes`, both attached fields empty (default), the search page wrapper id appears in the HTML, and the JS file contains `>= 2` references to `Drupal.quantSearch.renderHit` (definition + call site).

- [ ] **Step 3: Browser smoke test**

Load http://localhost:8084/whats-on-qs?finalcheck in a browser. Confirm:

1. Event-type facet renders as pills (horizontal).
2. Cost facet renders as a radio group with "All / free / paid".
3. Date range still works (May 2026 narrows to ~8 cards with session badges).
4. Layout toggle still switches between Cards and List.

If any of those four don't work, STOP and report BLOCKED with what specifically failed.

- [ ] **Step 4: Final syntax sweep**

```bash
cd /Users/stuart/apps/quant-drupal/.worktrees/quant-search-d7
for f in modules/quant_search/*.module modules/quant_search/*.inc modules/quant_search/*.install modules/quant_search/*.api.php; do
  php -l "$f" 2>&1 | grep -v "No syntax errors" || true
done
node --check modules/quant_search/js/quant-search.js
node --check modules/quant_search/js/quant-autocomplete.js
```

Expected: no syntax errors anywhere.

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/README.md
git commit -m "docs(quant_search): document the three Phase 3 extension points

Customisation section in the module README covers Drupal.quantSearch.renderHit,
hook_quant_search_settings_alter, and the per-page attached_js / attached_css
textareas, with one SLV-style renderHit example."
```

---

## Risks and notes

- **`cssClasses.root` on `refinementList`** — InstantSearch.js v4 supports this option; if a future upgrade changes the option shape, the `pills` widget styling won't apply. Detectable by inspecting the rendered DOM for the `quant-search--widget-pills` class.
- **`onerror=` inline attribute on `<img>`** — passed through the default `renderHit`. Sites overriding `renderHit` are responsible for their own missing-image handling.
- **`drupal_alter('quant_search_settings', ...)`** — invoked before the JS settings serialise. Hooks that mutate `$settings['facets']` should be careful not to introduce facet keys that weren't registered via `quant_search_api_add_facets()`; the search backend will accept refinements but won't return facet counts for unregistered attributes.
- **Per-page `attached_js`** — attached after the module's own `quant-search.js`, so site overrides of `Drupal.quantSearch.renderHit` always win. Sites that need to run *before* the module should attach from a custom module with `'every_page' => FALSE` and a lower weight; the per-page textarea is for the post-module use case.

## Self-review

- **Spec coverage:**
  - Spec §1 (radio/pills widgets) → Tasks 2 + 3.
  - Spec §2 (overridable renderHit) → Task 1.
  - Spec §3 (`hook_quant_search_settings_alter`) → Task 4.
  - Spec §4 (per-page attached JS/CSS) → Task 5.
  - Spec "Files touched" table → Tasks 1–6.
  - Spec "Backwards compatibility" → Task 1 Step 5 verification confirms the default `renderHit` produces unchanged output; Task 5 Step 3 nested-defaults block keeps older records loading.
  - Spec "Testing" 5 items → Task 2 Step 5 (radio), Task 3 Step 3 (pills), Task 6 (renderHit override path documented in README, full smoke), Task 4 Step 3 (hook fires), Task 6 Step 4 (php -l + node --check).
- **Placeholder scan:** no TBD/TODO; every code change is shown in full.
- **Name consistency:** `Drupal.quantSearch.renderHit`, `Drupal.quantSearch.radioWidget`, `quant-search--widget-radio`, `quant-search--widget-pills`, `attached_js` / `attached_css`, `hook_quant_search_settings_alter`, `quant_search_split_attached()` — used identically wherever they appear in the plan.
