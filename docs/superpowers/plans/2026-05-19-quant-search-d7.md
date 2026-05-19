# D7 quant_search Module — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Drupal 7 `quant_search` submodule that indexes content into Quant's hosted search and lets a site create faceted search pages (served at a route and/or embedded as blocks), including date/calendar filters.

**Architecture:** A lean, procedural D7 submodule of the `quant` module. Search-page definitions live in a custom DB table; per-entity index config lives in a variable. Content is indexed in real time via a shutdown function on entity save, plus a manual batch re-index. Search pages render an HTML shell + Algolia-compatible InstantSearch.js that queries Quant's search backend client-side. Routing is `hook_menu` enumeration; blocks are `hook_block_*`.

**Tech Stack:** Drupal 7, PHP, `drupal_http_request`, the `quant`/`quant_api`/`token` modules, InstantSearch.js v4 + algoliasearch v4 (CDN), native `<input type="date">` for date facets.

---

## Context for the implementer

- **Worktree:** Work in `/Users/stuart/apps/quant-drupal/.worktrees/quant-search-d7/` on branch `feat/quant-search-d7`. All module files go under `modules/quant_search/`. All paths below are relative to the worktree root.
- **Design spec:** `docs/superpowers/specs/2026-05-19-quant-search-d7-design.md` — read it first.
- **D9 reference module** (read-only, for porting): `/Users/stuart/apps/quant-drupal/modules/quant_search/` and `/Users/stuart/apps/quant-drupal/modules/quant_api/`. Do not modify it.
- **The host D7 `quant` module** is the worktree root itself (`quant.module`, `modules/quant_api/`, etc.).
- **Test site:** the running Drupal 7 site at `/Users/stuart/apps/slv-migration/www-slv-qc/` (`http://localhost:8084`, container service `drupal`). To run drush there: `cd /Users/stuart/apps/slv-migration/www-slv-qc && docker compose exec -T -w /opt/drupal drupal drush <cmd>`.
- **Quant test project:** organisation `state-library-victoria`, project `wwwslvvicgovau-dev`, endpoint `https://api.quantcdn.io`. The API **token is a secret** — it is configured at runtime via the `quant_api` settings form (`admin/config/services/quant/api`) on the test site, and must **never** be written into any file in this repo.
- This is a procedural D7 module. There is no unit-test harness; "tests" are verification commands (`php -l`, drush, HTTP checks on the running site). Each task ends with a commit. Use `git -c user.name="Claude" -c user.email="noreply@anthropic.com" commit` if git identity is unset.
- **Testing the module on the SLV site:** the module is developed in this worktree but enabled on the SLV site. After Task 1, symlink it once:
  `ln -s /Users/stuart/apps/quant-drupal/.worktrees/quant-search-d7/modules/quant_search /Users/stuart/apps/slv-migration/www-slv-qc/src/sites/all/modules/contrib/quantcdn/modules/quant_search`
  The SLV `quantcdn` module already has `modules/`; the symlink makes the new submodule discoverable. The container mounts `src/`, so the symlink is visible inside it. (If symlinks across the mount misbehave, `cp -R` the module in instead and re-copy after each task.)

This plan is organised in **three phases**, each ending at working, testable software:
- **Phase 1 (Tasks 1–6):** content indexing into Quant search.
- **Phase 2 (Tasks 7–11):** faceted search pages — CRUD, rendering, routing, JS, blocks.
- **Phase 3 (Tasks 12–14):** autocomplete block, admin overview, final verification.

---

## File structure

| Path | Responsibility |
|------|----------------|
| `modules/quant_search/quant_search.info` | Module metadata, dependencies |
| `modules/quant_search/quant_search.install` | `hook_schema` (search-page table), `hook_uninstall` |
| `modules/quant_search/quant_search.module` | `hook_menu`, `hook_permission`, `hook_theme`, `hook_block_*`, entity hooks, render entry points |
| `modules/quant_search/quant_search.api.inc` | Quant search HTTP API client functions |
| `modules/quant_search/quant_search.index.inc` | Search-record generation, batch re-index, shutdown push |
| `modules/quant_search/quant_search.admin.inc` | Overview/status, entities-config, batch-index, clear-index forms |
| `modules/quant_search/quant_search.pages.inc` | Search-page CRUD helpers + add/edit/delete form + pages list |
| `modules/quant_search/quant_search.theme.inc` | Shared search-page render builder + preprocess |
| `modules/quant_search/templates/quant-search-page.tpl.php` | Search-page HTML shell |
| `modules/quant_search/js/quant-search.js` | InstantSearch init (ported, instance-scoped, + date facet) |
| `modules/quant_search/js/quant-autocomplete.js` | Autocomplete (ported) |
| `modules/quant_search/css/quant-search.css` | Module styles |

---

# Phase 1 — Content indexing

### Task 1: Module skeleton and schema

**Files:**
- Create: `modules/quant_search/quant_search.info`
- Create: `modules/quant_search/quant_search.module`
- Create: `modules/quant_search/quant_search.install`

- [ ] **Step 1: Write `quant_search.info`**

```ini
name = Quant Search
description = Index content to Quant hosted search and build faceted search pages.
package = Quant
core = 7.x
configure = admin/config/services/quant/search
dependencies[] = quant
dependencies[] = quant:quant_api
dependencies[] = token
```

- [ ] **Step 2: Write `quant_search.install`**

Defines the `quant_search_page` table and cleans up variables on uninstall.

```php
<?php

/**
 * @file
 * Install, schema and uninstall hooks for Quant Search.
 */

/**
 * Implements hook_schema().
 */
function quant_search_schema() {
  $schema['quant_search_page'] = array(
    'description' => 'Faceted search page definitions.',
    'fields' => array(
      'id' => array(
        'type' => 'serial',
        'unsigned' => TRUE,
        'not null' => TRUE,
      ),
      'machine_name' => array(
        'type' => 'varchar',
        'length' => 64,
        'not null' => TRUE,
        'default' => '',
        'description' => 'Stable identifier: route, block delta, JS instance id.',
      ),
      'label' => array(
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
        'default' => '',
      ),
      'status' => array(
        'type' => 'int',
        'size' => 'tiny',
        'not null' => TRUE,
        'default' => 1,
      ),
      'route' => array(
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
        'default' => '',
        'description' => 'Drupal path. Empty means block-only.',
      ),
      'expose_block' => array(
        'type' => 'int',
        'size' => 'tiny',
        'not null' => TRUE,
        'default' => 0,
      ),
      'title' => array(
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
        'default' => '',
      ),
      'description' => array(
        'type' => 'text',
        'not null' => FALSE,
      ),
      'config' => array(
        'type' => 'blob',
        'size' => 'big',
        'not null' => FALSE,
        'serialize' => TRUE,
        'description' => 'Serialized array: languages, bundles, manual_filters, facets, display.',
      ),
    ),
    'primary key' => array('id'),
    'unique keys' => array(
      'machine_name' => array('machine_name'),
    ),
  );
  return $schema;
}

/**
 * Implements hook_uninstall().
 */
function quant_search_uninstall() {
  variable_del('quant_search_entities');
}
```

- [ ] **Step 3: Write `quant_search.module`** (skeleton — `hook_permission` only for now; later tasks add more hooks)

```php
<?php

/**
 * @file
 * Quant Search: index content to Quant hosted search and build search pages.
 */

/**
 * Implements hook_permission().
 */
function quant_search_permission() {
  return array(
    'administer quant search' => array(
      'title' => t('Administer Quant Search'),
      'description' => t('Configure the Quant search index and search pages.'),
      'restrict access' => TRUE,
    ),
  );
}
```

- [ ] **Step 4: Verify syntax**

```bash
php -l modules/quant_search/quant_search.module
php -l modules/quant_search/quant_search.install
```

Expected: "No syntax errors detected" for both.

- [ ] **Step 5: Symlink into the SLV test site and enable the module**

```bash
ln -sfn /Users/stuart/apps/quant-drupal/.worktrees/quant-search-d7/modules/quant_search \
  /Users/stuart/apps/slv-migration/www-slv-qc/src/sites/all/modules/contrib/quantcdn/modules/quant_search
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush en quant_search -y
docker compose exec -T -w /opt/drupal drupal drush sqlq "SHOW TABLES LIKE 'quant_search_page'"
```

Expected: the module enables (pulling in `quant`, `quant_api`, `token` if not already on); the `SHOW TABLES` query prints `quant_search_page`.

- [ ] **Step 6: Commit**

```bash
git add modules/quant_search
git commit -m "feat(quant_search): add module skeleton and search-page schema"
```

---

### Task 2: Quant search API client

**Files:**
- Create: `modules/quant_search/quant_search.api.inc`
- Modify: `modules/quant_search/quant_search.module` (add an `include_once` so the API file loads)

- [ ] **Step 1: Write `quant_search.api.inc`**

All functions reuse `quant_api_get_request_headers()` (defined in `modules/quant_api/quant_api.module`) for auth and `QUANT_API_ENDPOINT_DEFAULT` for the base URL. Endpoints confirmed from the D9 `QuantClient`: `POST /v1/search`, `DELETE /v1/search/all`, `POST /v1/search/facet`, `GET /v1/ping`, `GET /v1/search`.

```php
<?php

/**
 * @file
 * Quant hosted-search HTTP API client.
 */

/**
 * Builds a fully-qualified Quant API v1 URL.
 */
function quant_search_api_url($path) {
  $endpoint = variable_get('quant_api_endpoint', QUANT_API_ENDPOINT_DEFAULT);
  return rtrim($endpoint, '/') . '/v1' . $path;
}

/**
 * Performs a Quant search API request.
 *
 * @param string $path
 *   Path under /v1, e.g. '/search'.
 * @param string $method
 *   HTTP method.
 * @param array|null $body
 *   Optional payload; JSON-encoded when present.
 *
 * @return object
 *   The drupal_http_request() result. Inspect ->code and ->data.
 */
function quant_search_api_request($path, $method = 'GET', $body = NULL) {
  $headers = quant_api_get_request_headers();
  $options = array('method' => $method, 'headers' => $headers, 'timeout' => 30);
  if ($body !== NULL) {
    $options['headers']['Content-Type'] = 'application/json';
    $options['data'] = drupal_json_encode($body);
  }
  return drupal_http_request(quant_search_api_url($path), $options);
}

/**
 * Returns the Quant project, including search credentials.
 *
 * @return object|false
 *   Decoded project object, or FALSE on failure. Search fields live at
 *   ->config->search_index->{algolia_application_id,algolia_read_key,algolia_index}
 *   and ->config->search_enabled.
 */
function quant_search_api_project() {
  $response = quant_search_api_request('/ping', 'GET');
  if ($response->code == 200 && !empty($response->data)) {
    return drupal_json_decode($response->data, FALSE);
  }
  watchdog('quant_search', 'Project lookup failed: @code', array('@code' => $response->code), WATCHDOG_WARNING);
  return FALSE;
}

/**
 * Returns TRUE when hosted search is enabled for the project.
 */
function quant_search_api_enabled() {
  $project = quant_search_api_project();
  return $project && !empty($project->config->search_enabled);
}

/**
 * Sends a batch of search records to the index.
 *
 * @param array $records
 *   A list of record arrays (see quant_search_generate_record()).
 *
 * @return bool
 *   TRUE on HTTP 200.
 */
function quant_search_api_send_records(array $records) {
  if (empty($records)) {
    return TRUE;
  }
  $response = quant_search_api_request('/search', 'POST', array_values($records));
  if ($response->code != 200) {
    watchdog('quant_search', 'Send records failed: @code @data',
      array('@code' => $response->code, '@data' => substr((string) $response->data, 0, 500)),
      WATCHDOG_ERROR);
    return FALSE;
  }
  return TRUE;
}

/**
 * Clears the entire search index.
 */
function quant_search_api_clear() {
  $response = quant_search_api_request('/search/all', 'DELETE');
  return $response->code == 200;
}

/**
 * Registers facetable attribute names with the search backend.
 *
 * @param array $keys
 *   Flat list of attribute names. 'lang_code' and 'content_type' are always
 *   added.
 */
function quant_search_api_add_facets(array $keys) {
  $unique = array_values(array_unique(array_merge(array('lang_code', 'content_type'), $keys)));
  $response = quant_search_api_request('/search/facet', 'POST', $unique);
  return $response->code == 200;
}

/**
 * Returns index statistics, or FALSE.
 *
 * Response has ->index and ->settings when stats are available.
 */
function quant_search_api_stats() {
  $response = quant_search_api_request('/search', 'GET');
  if ($response->code == 200 && !empty($response->data)) {
    return drupal_json_decode($response->data, FALSE);
  }
  return FALSE;
}
```

- [ ] **Step 2: Make the module load the API include**

In `quant_search.module`, add this near the top, immediately after the `@file` docblock block and before `hook_permission`:

```php
module_load_include('inc', 'quant_search', 'quant_search.api');
```

- [ ] **Step 3: Verify syntax**

```bash
php -l modules/quant_search/quant_search.api.inc
php -l modules/quant_search/quant_search.module
```

Expected: "No syntax errors detected" for both.

- [ ] **Step 4: Verify against the live Quant project**

First, on the test site, configure `quant_api` credentials for the `wwwslvvicgovau-dev` project at `http://localhost:8084/admin/config/services/quant/api` (organisation `state-library-victoria`, project `wwwslvvicgovau-dev`, token supplied by the project owner — do NOT put the token in any file). Then:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush ev \
  'module_load_include("inc","quant_search","quant_search.api"); $p=quant_search_api_project(); var_dump($p ? $p->config->search_enabled : "NO PROJECT");'
```

Expected: prints the `search_enabled` flag (a bool). If it prints `"NO PROJECT"`, the credentials are not configured or the API is unreachable — resolve before continuing. If `search_enabled` is `false`, note it: indexing calls will be rejected by the backend until search is enabled for the project — flag this to the project owner but continue the build.

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/quant_search.api.inc modules/quant_search/quant_search.module
git commit -m "feat(quant_search): add Quant search API client"
```

---

### Task 3: Per-entity index configuration

**Files:**
- Create: `modules/quant_search/quant_search.admin.inc`
- Modify: `modules/quant_search/quant_search.module` (add `hook_menu`)

- [ ] **Step 1: Add `hook_menu` to `quant_search.module`**

Add this function to `quant_search.module`. It registers the admin tabs under the parent module's `admin/config/services/quant` base. (Later tasks add more items to this same function — for now it has the base + entities tab.)

```php
/**
 * Implements hook_menu().
 */
function quant_search_menu() {
  $items = array();

  $items['admin/config/services/quant/search'] = array(
    'title' => 'Search',
    'description' => 'Quant hosted search configuration.',
    'page callback' => 'quant_search_admin_overview',
    'access arguments' => array('administer quant search'),
    'type' => MENU_LOCAL_TASK,
    'weight' => 60,
    'file' => 'quant_search.admin.inc',
  );
  $items['admin/config/services/quant/search/overview'] = array(
    'title' => 'Overview',
    'type' => MENU_DEFAULT_LOCAL_TASK,
    'weight' => -10,
  );
  $items['admin/config/services/quant/search/entities'] = array(
    'title' => 'Entities',
    'description' => 'Choose which content is indexed.',
    'page callback' => 'drupal_get_form',
    'page arguments' => array('quant_search_entities_form'),
    'access arguments' => array('administer quant search'),
    'type' => MENU_LOCAL_TASK,
    'weight' => 10,
    'file' => 'quant_search.admin.inc',
  );

  return $items;
}
```

- [ ] **Step 2: Write `quant_search.admin.inc` with the entities form**

This file will gain more forms in Task 6 and Task 13. For now: a stub overview page callback and the entities config form. The entities form configures, per indexable entity type (`node` and `taxonomy_term`), which bundles/languages are indexed, the view mode used to render the body, the token patterns for title/summary/image, and which date fields index as numeric timestamps.

```php
<?php

/**
 * @file
 * Quant Search admin pages.
 */

/**
 * Page callback: overview/status (fleshed out in a later task).
 */
function quant_search_admin_overview() {
  return array(
    '#markup' => t('Quant Search overview. Use the tabs to configure indexing and search pages.'),
  );
}

/**
 * Form: which entities and fields are indexed.
 */
function quant_search_entities_form($form, &$form_state) {
  $config = variable_get('quant_search_entities', array());

  $form['#tree'] = TRUE;
  $form['intro'] = array(
    '#markup' => '<p>' . t('Choose which content is pushed to the Quant search index.') . '</p>',
  );

  // Node configuration.
  $form['node'] = array(
    '#type' => 'fieldset',
    '#title' => t('Content (nodes)'),
    '#collapsible' => TRUE,
  );
  $form['node']['enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Index nodes'),
    '#default_value' => !empty($config['node']['enabled']),
  );
  $bundle_options = array();
  foreach (node_type_get_types() as $type => $info) {
    $bundle_options[$type] = $info->name;
  }
  $form['node']['bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Content types to index'),
    '#options' => $bundle_options,
    '#default_value' => isset($config['node']['bundles']) ? $config['node']['bundles'] : array(),
  );
  $form['node']['view_mode'] = array(
    '#type' => 'select',
    '#title' => t('View mode used to render the indexed body'),
    '#options' => quant_search_view_mode_options('node'),
    '#default_value' => isset($config['node']['view_mode']) ? $config['node']['view_mode'] : 'full',
  );
  $form['node']['title_token'] = array(
    '#type' => 'textfield',
    '#title' => t('Title token'),
    '#default_value' => isset($config['node']['title_token']) ? $config['node']['title_token'] : '[node:title]',
  );
  $form['node']['summary_token'] = array(
    '#type' => 'textfield',
    '#title' => t('Summary token'),
    '#default_value' => isset($config['node']['summary_token']) ? $config['node']['summary_token'] : '[node:summary]',
  );
  $form['node']['image_token'] = array(
    '#type' => 'textfield',
    '#title' => t('Image token'),
    '#default_value' => isset($config['node']['image_token']) ? $config['node']['image_token'] : '',
  );
  $form['node']['date_fields'] = array(
    '#type' => 'textfield',
    '#title' => t('Date fields'),
    '#description' => t('Comma-separated machine names of date fields to index as numeric timestamps (for date-range facets), e.g. field_event_session.'),
    '#default_value' => isset($config['node']['date_fields']) ? $config['node']['date_fields'] : '',
  );
  if (module_exists('token')) {
    $form['node']['token_help'] = array(
      '#theme' => 'token_tree_link',
      '#token_types' => array('node'),
    );
  }

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Save configuration'));
  return $form;
}

/**
 * Submit handler for quant_search_entities_form().
 */
function quant_search_entities_form_submit($form, &$form_state) {
  $v = $form_state['values'];
  $config = array(
    'node' => array(
      'enabled' => (bool) $v['node']['enabled'],
      'bundles' => array_values(array_filter($v['node']['bundles'])),
      'view_mode' => $v['node']['view_mode'],
      'title_token' => trim($v['node']['title_token']),
      'summary_token' => trim($v['node']['summary_token']),
      'image_token' => trim($v['node']['image_token']),
      'date_fields' => trim($v['node']['date_fields']),
    ),
  );
  variable_set('quant_search_entities', $config);
  drupal_set_message(t('Quant search indexing configuration saved.'));
}

/**
 * Returns view-mode options for an entity type.
 */
function quant_search_view_mode_options($entity_type) {
  $options = array('full' => t('Full content'), 'teaser' => t('Teaser'));
  $info = entity_get_info($entity_type);
  if (!empty($info['view modes'])) {
    foreach ($info['view modes'] as $mode => $mode_info) {
      $options[$mode] = $mode_info['label'];
    }
  }
  return $options;
}
```

- [ ] **Step 3: Verify syntax and the form**

```bash
php -l modules/quant_search/quant_search.admin.inc
php -l modules/quant_search/quant_search.module
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8084/admin/config/services/quant/search/entities"
```

Expected: no syntax errors; the entities page returns `200` (or `403` if not logged in as admin — if so, verify via `drush` that the menu item exists: `drush ev 'menu_rebuild(); $m=menu_get_item("admin/config/services/quant/search/entities"); var_dump(!empty($m));'` prints `bool(true)`).

- [ ] **Step 4: Verify the form saves**

Log in as admin, open `/admin/config/services/quant/search/entities`, tick "Index nodes", select one or two content types (include `event` if present), set the date field to `field_event_session`, save. Then:

```bash
docker compose exec -T -w /opt/drupal drupal drush vget quant_search_entities
```

Expected: the saved config array prints, showing `enabled => true`, the chosen bundles, and `date_fields => field_event_session`.

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/quant_search.admin.inc modules/quant_search/quant_search.module
git commit -m "feat(quant_search): add per-entity index configuration form"
```

---

### Task 4: Search-record generation

**Files:**
- Create: `modules/quant_search/quant_search.index.inc`
- Modify: `modules/quant_search/quant_search.module` (load the index include)

- [ ] **Step 1: Write `quant_search.index.inc` — record generation**

`quant_search_generate_record()` mirrors the D9 record shape so the index stays version-compatible: `title`, `summary`, `content`, `image`, `url`, `lang_code`, `content_type`, `content_type_{lang}`, taxonomy `{vocabulary}_{lang}` arrays, and date attributes as numeric Unix timestamps (arrays for multi-value fields).

```php
<?php

/**
 * @file
 * Quant Search indexing: record generation and batch re-index.
 */

/**
 * Builds a search record for one node.
 *
 * @param object $node
 *   The node.
 *
 * @return array|false
 *   The record array, or FALSE if the node's bundle is not indexed.
 */
function quant_search_generate_record($node) {
  $config = variable_get('quant_search_entities', array());
  if (empty($config['node']['enabled']) || empty($config['node']['bundles'])
      || !in_array($node->type, $config['node']['bundles'], TRUE)) {
    return FALSE;
  }

  $langcode = !empty($node->language) && $node->language != LANGUAGE_NONE ? $node->language : 'en';
  $record = array();

  // Token-driven fields.
  $data = array('node' => $node);
  $opts = array('clear' => TRUE, 'sanitize' => FALSE);
  foreach (array('title' => 'title_token', 'summary' => 'summary_token', 'image' => 'image_token') as $key => $cfg) {
    if (!empty($config['node'][$cfg])) {
      $value = trim(token_replace($config['node'][$cfg], $data, $opts));
      if ($value !== '') {
        $record[$key] = ($key === 'image') ? $value : decode_entities(strip_tags($value));
      }
    }
  }

  // Rendered body.
  $view_mode = !empty($config['node']['view_mode']) ? $config['node']['view_mode'] : 'full';
  $build = node_view($node, $view_mode);
  $record['content'] = decode_entities(trim(strip_tags(drupal_render($build))));

  // Core attributes.
  $uri = entity_uri('node', $node);
  $record['url'] = '/' . drupal_get_path_alias($uri['path']);
  $record['lang_code'] = $langcode;
  $record['content_type'] = $node->type;
  $type_info = node_type_get_type($node);
  $record['content_type_' . $langcode] = $type_info ? $type_info->name : $node->type;

  // Taxonomy terms, grouped by vocabulary.
  $record += quant_search_node_terms($node, $langcode);

  // Date fields as numeric timestamps.
  $record += quant_search_node_dates($node, $config['node']['date_fields']);

  return $record;
}

/**
 * Returns taxonomy term names for a node, keyed {vocabulary}_{langcode}.
 */
function quant_search_node_terms($node, $langcode) {
  $out = array();
  $tids = db_query('SELECT tid FROM {taxonomy_index} WHERE nid = :nid', array(':nid' => $node->nid))->fetchCol();
  if (!$tids) {
    return $out;
  }
  foreach (taxonomy_term_load_multiple($tids) as $term) {
    $vocab = taxonomy_vocabulary_load($term->vid);
    if (!$vocab) {
      continue;
    }
    $key = $vocab->machine_name . '_' . $langcode;
    $out[$key][] = $term->name;
  }
  return $out;
}

/**
 * Extracts date-field values from a node as numeric Unix timestamps.
 *
 * @param object $node
 *   The node.
 * @param string $date_fields
 *   Comma-separated date field machine names.
 *
 * @return array
 *   Map of {field_name} => array of integer timestamps. Multi-value and
 *   recurring date fields yield multiple timestamps so a date-range facet
 *   matches if any occurrence falls within range.
 */
function quant_search_node_dates($node, $date_fields) {
  $out = array();
  $fields = array_filter(array_map('trim', explode(',', (string) $date_fields)));
  foreach ($fields as $field_name) {
    $items = field_get_items('node', $node, $field_name);
    if (!$items) {
      continue;
    }
    $stamps = array();
    foreach ($items as $item) {
      // Date fields store ISO strings or timestamps in 'value'.
      if (isset($item['value'])) {
        $ts = is_numeric($item['value']) ? (int) $item['value'] : strtotime($item['value']);
        if ($ts) {
          $stamps[] = $ts;
        }
      }
    }
    if ($stamps) {
      $out[$field_name] = $stamps;
    }
  }
  return $out;
}
```

- [ ] **Step 2: Load the index include from the module**

In `quant_search.module`, below the existing `module_load_include('inc', 'quant_search', 'quant_search.api');` line, add:

```php
module_load_include('inc', 'quant_search', 'quant_search.index');
```

- [ ] **Step 3: Verify syntax**

```bash
php -l modules/quant_search/quant_search.index.inc
php -l modules/quant_search/quant_search.module
```

Expected: "No syntax errors detected".

- [ ] **Step 4: Verify record generation against a real node**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
docker compose exec -T -w /opt/drupal drupal drush ev \
  '$nid=db_query_range("SELECT nid FROM {node} WHERE status=1 AND type IN (SELECT type FROM {node})",0,1)->fetchField(); $n=node_load($nid); print_r(quant_search_generate_record($n));'
```

Expected: prints either a record array (if that node's bundle is configured for indexing — keys `title`, `content`, `url`, `lang_code`, `content_type`, etc.) or an empty result / `FALSE` (if the bundle is not indexed). To force a positive result, pick a node whose type you enabled in Task 3 — replace the query with `WHERE status=1 AND type='event'` (or another enabled type).

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/quant_search.index.inc modules/quant_search/quant_search.module
git commit -m "feat(quant_search): add search-record generation"
```

---

### Task 5: Real-time indexing hooks

**Files:**
- Modify: `modules/quant_search/quant_search.module` (entity hooks)
- Modify: `modules/quant_search/quant_search.index.inc` (shutdown push helper)

- [ ] **Step 1: Add the shutdown-push helper to `quant_search.index.inc`**

Append these functions to `quant_search.index.inc`:

```php
/**
 * Queues a node to be pushed to the search index after the response.
 *
 * Mirrors the parent quant module's reactive-push pattern: the API call
 * runs in a shutdown function so content saves take no latency hit.
 */
function quant_search_index_node($node) {
  $pending = &drupal_static('quant_search_pending_nodes', array());
  $pending[$node->nid] = $node;
  drupal_register_shutdown_function('quant_search_index_shutdown');
}

/**
 * Shutdown callback: builds and sends records for queued nodes.
 */
function quant_search_index_shutdown() {
  $pending = &drupal_static('quant_search_pending_nodes', array());
  if (empty($pending)) {
    return;
  }
  $records = array();
  foreach ($pending as $node) {
    $record = quant_search_generate_record($node);
    if ($record) {
      $records[] = $record;
    }
  }
  $pending = array();
  if ($records) {
    quant_search_api_send_records($records);
  }
}
```

- [ ] **Step 2: Add entity hooks to `quant_search.module`**

Add these hooks to `quant_search.module`:

```php
/**
 * Implements hook_node_insert().
 */
function quant_search_node_insert($node) {
  if (!empty($node->status)) {
    quant_search_index_node($node);
  }
}

/**
 * Implements hook_node_update().
 */
function quant_search_node_update($node) {
  if (!empty($node->status)) {
    quant_search_index_node($node);
  }
  // Note: per-record removal on unpublish is not supported by the current
  // Quant search API (no single-record DELETE). Unpublished/deleted content
  // is reconciled by the next full re-index. See README.
}
```

> **Implementer note on deletes:** the D9 `QuantClient` exposes no single-record delete, only `DELETE /v1/search/all`. Do **not** invent a delete endpoint. If, when verifying against the live `wwwslvvicgovau-dev` API, you discover a working per-record delete (e.g. `DELETE /v1/search` with a `Quant-Url` header, mirroring content unpublish), add a `quant_search_api_delete_record($url)` function and a `hook_node_delete` that calls it. Otherwise leave deletes to the re-index and document the limitation in the README (Task 14).

- [ ] **Step 3: Verify syntax**

```bash
php -l modules/quant_search/quant_search.module
php -l modules/quant_search/quant_search.index.inc
```

Expected: "No syntax errors detected".

- [ ] **Step 4: Verify real-time indexing**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
# Re-save one published node of an indexed type and confirm an API call is attempted.
docker compose exec -T -w /opt/drupal drupal drush ev \
  '$nid=db_query_range("SELECT nid FROM {node} WHERE status=1",0,1)->fetchField(); $n=node_load($nid); node_save($n); echo "saved $nid\n";'
docker compose exec -T -w /opt/drupal drupal drush watchdog-show --count=5 --type=quant_search
```

Expected: the node saves without error. If its bundle is indexed and the project has search enabled, no error appears in watchdog; if `search_enabled` is false on the project, a `quant_search` watchdog error from `quant_search_api_send_records()` is expected and acceptable at this stage — it confirms the push fired.

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/quant_search.module modules/quant_search/quant_search.index.inc
git commit -m "feat(quant_search): index nodes in real time on save"
```

---

### Task 6: Batch re-index and clear-index forms

**Files:**
- Modify: `modules/quant_search/quant_search.module` (`hook_menu` items)
- Modify: `modules/quant_search/quant_search.admin.inc` (two forms + batch op)

- [ ] **Step 1: Add menu items**

In `quant_search_menu()` in `quant_search.module`, add these items before `return $items;`:

```php
  $items['admin/config/services/quant/search/index'] = array(
    'title' => 'Index',
    'description' => 'Re-index all content.',
    'page callback' => 'drupal_get_form',
    'page arguments' => array('quant_search_index_form'),
    'access arguments' => array('administer quant search'),
    'type' => MENU_LOCAL_TASK,
    'weight' => 20,
    'file' => 'quant_search.admin.inc',
  );
  $items['admin/config/services/quant/search/clear'] = array(
    'title' => 'Clear index',
    'page callback' => 'drupal_get_form',
    'page arguments' => array('quant_search_clear_form'),
    'access arguments' => array('administer quant search'),
    'type' => MENU_LOCAL_ACTION,
    'weight' => 30,
    'file' => 'quant_search.admin.inc',
  );
```

- [ ] **Step 2: Add the batch re-index form and clear form to `quant_search.admin.inc`**

Append to `quant_search.admin.inc`:

```php
/**
 * Form: trigger a full re-index.
 */
function quant_search_index_form($form, &$form_state) {
  $form['help'] = array(
    '#markup' => '<p>' . t('Re-index all published content of the configured types into Quant search.') . '</p>',
  );
  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Re-index now'));
  return $form;
}

/**
 * Submit handler: builds a batch over all indexed nodes.
 */
function quant_search_index_form_submit($form, &$form_state) {
  $config = variable_get('quant_search_entities', array());
  $bundles = !empty($config['node']['bundles']) ? $config['node']['bundles'] : array();
  if (empty($config['node']['enabled']) || empty($bundles)) {
    drupal_set_message(t('No content types are configured for indexing.'), 'warning');
    return;
  }
  $nids = db_select('node', 'n')
    ->fields('n', array('nid'))
    ->condition('status', 1)
    ->condition('type', $bundles, 'IN')
    ->execute()
    ->fetchCol();

  $operations = array();
  foreach (array_chunk($nids, 50) as $chunk) {
    $operations[] = array('quant_search_index_batch_op', array($chunk));
  }
  batch_set(array(
    'title' => t('Indexing content'),
    'operations' => $operations,
    'finished' => 'quant_search_index_batch_finished',
    'file' => drupal_get_path('module', 'quant_search') . '/quant_search.admin.inc',
  ));
}

/**
 * Batch operation: index one chunk of node ids.
 */
function quant_search_index_batch_op($nids, &$context) {
  module_load_include('inc', 'quant_search', 'quant_search.index');
  module_load_include('inc', 'quant_search', 'quant_search.api');
  $records = array();
  foreach (node_load_multiple($nids) as $node) {
    $record = quant_search_generate_record($node);
    if ($record) {
      $records[] = $record;
    }
  }
  if ($records) {
    quant_search_api_send_records($records);
  }
  $context['results']['count'] = (isset($context['results']['count']) ? $context['results']['count'] : 0) + count($records);
}

/**
 * Batch finished callback.
 */
function quant_search_index_batch_finished($success, $results, $operations) {
  if ($success) {
    drupal_set_message(t('Indexed @n records.', array('@n' => isset($results['count']) ? $results['count'] : 0)));
  }
  else {
    drupal_set_message(t('Indexing finished with errors.'), 'error');
  }
}

/**
 * Form: confirm clearing the whole index.
 */
function quant_search_clear_form($form, &$form_state) {
  return confirm_form(
    array(),
    t('Clear the entire Quant search index?'),
    'admin/config/services/quant/search',
    t('This removes every record. It cannot be undone.'),
    t('Clear index'),
    t('Cancel')
  );
}

/**
 * Submit handler: clears the index.
 */
function quant_search_clear_form_submit($form, &$form_state) {
  module_load_include('inc', 'quant_search', 'quant_search.api');
  if (quant_search_api_clear()) {
    drupal_set_message(t('Search index cleared.'));
  }
  else {
    drupal_set_message(t('Failed to clear the search index.'), 'error');
  }
  $form_state['redirect'] = 'admin/config/services/quant/search';
}
```

- [ ] **Step 3: Verify syntax**

```bash
php -l modules/quant_search/quant_search.admin.inc
php -l modules/quant_search/quant_search.module
```

Expected: "No syntax errors detected".

- [ ] **Step 4: Verify batch re-index end to end**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
docker compose exec -T -w /opt/drupal drupal drush ev \
  'module_load_include("inc","quant_search","quant_search.admin"); module_load_include("inc","quant_search","quant_search.index"); module_load_include("inc","quant_search","quant_search.api");
   $cfg=variable_get("quant_search_entities",array()); $b=$cfg["node"]["bundles"];
   $nids=db_select("node","n")->fields("n",array("nid"))->condition("status",1)->condition("type",$b,"IN")->range(0,50)->execute()->fetchCol();
   $ctx=array(); quant_search_index_batch_op($nids,$ctx); print_r($ctx["results"]);'
```

Expected: prints `Array ( [count] => N )` with N > 0 (assuming indexed bundles have published nodes and the project has search enabled). If `search_enabled` is false a watchdog error is logged but the count still reflects records generated.

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/quant_search.module modules/quant_search/quant_search.admin.inc
git commit -m "feat(quant_search): add batch re-index and clear-index forms"
```

**Phase 1 complete:** content indexing works — real-time on save and via batch re-index, with a clear-index action.

---

# Phase 2 — Faceted search pages

### Task 7: Search-page CRUD helpers

**Files:**
- Create: `modules/quant_search/quant_search.pages.inc`
- Modify: `modules/quant_search/quant_search.module` (load the include)

- [ ] **Step 1: Write the CRUD section of `quant_search.pages.inc`**

```php
<?php

/**
 * @file
 * Quant Search: search-page CRUD and admin forms.
 */

/**
 * Loads one search page by id.
 *
 * @return array|false
 *   Associative array with keys: id, machine_name, label, status, route,
 *   expose_block, title, description, and the unpacked config keys
 *   (languages, bundles, manual_filters, facets, display).
 */
function quant_search_page_load($id) {
  $row = db_select('quant_search_page', 'p')->fields('p')->condition('id', $id)->execute()->fetchAssoc();
  return $row ? quant_search_page_unpack($row) : FALSE;
}

/**
 * Loads one search page by machine name.
 */
function quant_search_page_load_by_name($name) {
  $row = db_select('quant_search_page', 'p')->fields('p')->condition('machine_name', $name)->execute()->fetchAssoc();
  return $row ? quant_search_page_unpack($row) : FALSE;
}

/**
 * Loads all search pages.
 *
 * @param bool $enabled_only
 *   When TRUE, only status=1 pages are returned.
 *
 * @return array
 *   Search-page arrays keyed by id.
 */
function quant_search_page_load_all($enabled_only = FALSE) {
  $query = db_select('quant_search_page', 'p')->fields('p')->orderBy('label');
  if ($enabled_only) {
    $query->condition('status', 1);
  }
  $pages = array();
  foreach ($query->execute() as $row) {
    $pages[$row->id] = quant_search_page_unpack((array) $row);
  }
  return $pages;
}

/**
 * Expands a raw DB row: unserializes config into top-level keys.
 */
function quant_search_page_unpack($row) {
  $config = !empty($row['config']) ? unserialize($row['config']) : array();
  $config += array(
    'languages' => array(),
    'bundles' => array(),
    'manual_filters' => '',
    'facets' => array(),
    'display' => array(
      'results' => array('display_search' => 1, 'display_stats' => 1, 'show_clear_refinements' => 1),
      'pagination' => array('pagination_enabled' => 1, 'per_page' => 20),
    ),
  );
  unset($row['config']);
  return array_merge($row, $config);
}

/**
 * Saves a search page (insert or update).
 *
 * @param array $page
 *   A page array as returned by quant_search_page_unpack().
 *
 * @return int
 *   The page id.
 */
function quant_search_page_save($page) {
  $record = array(
    'machine_name' => $page['machine_name'],
    'label' => $page['label'],
    'status' => !empty($page['status']) ? 1 : 0,
    'route' => isset($page['route']) ? $page['route'] : '',
    'expose_block' => !empty($page['expose_block']) ? 1 : 0,
    'title' => isset($page['title']) ? $page['title'] : '',
    'description' => isset($page['description']) ? $page['description'] : '',
    'config' => array(
      'languages' => isset($page['languages']) ? $page['languages'] : array(),
      'bundles' => isset($page['bundles']) ? $page['bundles'] : array(),
      'manual_filters' => isset($page['manual_filters']) ? $page['manual_filters'] : '',
      'facets' => isset($page['facets']) ? $page['facets'] : array(),
      'display' => isset($page['display']) ? $page['display'] : array(),
    ),
  );
  if (!empty($page['id'])) {
    $record['id'] = $page['id'];
    drupal_write_record('quant_search_page', $record, 'id');
  }
  else {
    drupal_write_record('quant_search_page', $record);
  }
  return $record['id'];
}

/**
 * Deletes a search page.
 */
function quant_search_page_delete($id) {
  db_delete('quant_search_page')->condition('id', $id)->execute();
}
```

Note: the `config` column has `'serialize' => TRUE` in the schema, so `drupal_write_record()` serializes the nested array automatically and `quant_search_page_unpack()` unserializes it.

- [ ] **Step 2: Load the include from the module**

In `quant_search.module`, add below the other `module_load_include` lines:

```php
module_load_include('inc', 'quant_search', 'quant_search.pages');
```

- [ ] **Step 3: Verify syntax and CRUD**

```bash
php -l modules/quant_search/quant_search.pages.inc
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
docker compose exec -T -w /opt/drupal drupal drush ev \
  '$id=quant_search_page_save(array("machine_name"=>"crud_test","label"=>"CRUD test","status"=>1,"route"=>"crud-test","facets"=>array()));
   $p=quant_search_page_load($id); echo "loaded: ".$p["label"]." facets=".count($p["facets"])."\n";
   quant_search_page_delete($id); echo "after delete: ".(quant_search_page_load($id)?"STILL THERE":"gone")."\n";'
```

Expected: prints `loaded: CRUD test facets=0` then `after delete: gone`.

- [ ] **Step 4: Commit**

```bash
git add modules/quant_search/quant_search.pages.inc modules/quant_search/quant_search.module
git commit -m "feat(quant_search): add search-page CRUD helpers"
```

---

### Task 8: Search-page admin form and list

**Files:**
- Modify: `modules/quant_search/quant_search.module` (`hook_menu` items)
- Modify: `modules/quant_search/quant_search.pages.inc` (list page + add/edit/delete forms)

- [ ] **Step 1: Add menu items**

In `quant_search_menu()`, add before `return $items;`:

```php
  $items['admin/config/services/quant/search/pages'] = array(
    'title' => 'Pages',
    'description' => 'Manage search pages.',
    'page callback' => 'quant_search_pages_list',
    'access arguments' => array('administer quant search'),
    'type' => MENU_LOCAL_TASK,
    'weight' => 5,
    'file' => 'quant_search.pages.inc',
  );
  $items['admin/config/services/quant/search/pages/add'] = array(
    'title' => 'Add search page',
    'page callback' => 'drupal_get_form',
    'page arguments' => array('quant_search_page_form'),
    'access arguments' => array('administer quant search'),
    'type' => MENU_LOCAL_ACTION,
    'file' => 'quant_search.pages.inc',
  );
  $items['admin/config/services/quant/search/pages/%/edit'] = array(
    'title' => 'Edit search page',
    'page callback' => 'drupal_get_form',
    'page arguments' => array('quant_search_page_form', 6),
    'access arguments' => array('administer quant search'),
    'type' => MENU_CALLBACK,
    'file' => 'quant_search.pages.inc',
  );
  $items['admin/config/services/quant/search/pages/%/delete'] = array(
    'title' => 'Delete search page',
    'page callback' => 'drupal_get_form',
    'page arguments' => array('quant_search_page_delete_form', 6),
    'access arguments' => array('administer quant search'),
    'type' => MENU_CALLBACK,
    'file' => 'quant_search.pages.inc',
  );
```

- [ ] **Step 2: Add the pages list to `quant_search.pages.inc`**

```php
/**
 * Page callback: lists search pages.
 */
function quant_search_pages_list() {
  $header = array(t('Label'), t('Machine name'), t('Route'), t('Block'), t('Status'), t('Operations'));
  $rows = array();
  foreach (quant_search_page_load_all() as $page) {
    $route = $page['route'] !== '' ? l($page['route'], $page['route']) : t('(block only)');
    $rows[] = array(
      check_plain($page['label']),
      check_plain($page['machine_name']),
      $route,
      $page['expose_block'] ? t('Yes') : t('No'),
      $page['status'] ? t('Enabled') : t('Disabled'),
      l(t('Edit'), 'admin/config/services/quant/search/pages/' . $page['id'] . '/edit')
        . ' | ' . l(t('Delete'), 'admin/config/services/quant/search/pages/' . $page['id'] . '/delete'),
    );
  }
  return array(
    '#theme' => 'table',
    '#header' => $header,
    '#rows' => $rows,
    '#empty' => t('No search pages yet. Use "Add search page" to create one.'),
  );
}
```

- [ ] **Step 3: Add the add/edit form to `quant_search.pages.inc`**

The form covers identity, routing, content scoping, the facet list (AJAX add/remove, tabledrag weight), and display options. Facet rows are stored in `$form_state['facets']` between AJAX rebuilds.

```php
/**
 * Form: add/edit a search page.
 */
function quant_search_page_form($form, &$form_state, $id = NULL) {
  $page = $id ? quant_search_page_load($id) : NULL;
  if ($id && !$page) {
    drupal_not_found();
    drupal_exit();
  }
  $form['#tree'] = TRUE;
  $form_state['page_id'] = $page ? $page['id'] : NULL;

  // Seed the working facet list once.
  if (!isset($form_state['facets'])) {
    $form_state['facets'] = $page ? array_values($page['facets']) : array();
  }

  $form['label'] = array(
    '#type' => 'textfield',
    '#title' => t('Label'),
    '#required' => TRUE,
    '#default_value' => $page ? $page['label'] : '',
  );
  $form['machine_name'] = array(
    '#type' => 'machine_name',
    '#default_value' => $page ? $page['machine_name'] : '',
    '#machine_name' => array('exists' => 'quant_search_page_load_by_name', 'source' => array('label')),
    '#disabled' => (bool) $page,
  );
  $form['status'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enabled'),
    '#default_value' => $page ? $page['status'] : 1,
  );
  $form['route'] = array(
    '#type' => 'textfield',
    '#title' => t('Route'),
    '#description' => t('URL path for this search page, no leading slash, e.g. search or whats-on. Leave empty for a block-only page.'),
    '#default_value' => $page ? $page['route'] : '',
  );
  $form['expose_block'] = array(
    '#type' => 'checkbox',
    '#title' => t('Also expose this search page as a block'),
    '#default_value' => $page ? $page['expose_block'] : 0,
  );
  $form['title'] = array(
    '#type' => 'textfield',
    '#title' => t('Page title'),
    '#default_value' => $page ? $page['title'] : '',
  );
  $form['description'] = array(
    '#type' => 'textarea',
    '#title' => t('Description'),
    '#default_value' => $page ? $page['description'] : '',
  );

  // Content scoping.
  $form['scope'] = array('#type' => 'fieldset', '#title' => t('Result scope'), '#collapsible' => TRUE);
  $bundle_options = array();
  foreach (node_type_get_types() as $type => $info) {
    $bundle_options[$type] = $info->name;
  }
  $form['scope']['bundles'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Limit to content types'),
    '#options' => $bundle_options,
    '#default_value' => $page ? $page['bundles'] : array(),
  );
  $lang_options = array();
  foreach (language_list() as $code => $lang) {
    $lang_options[$code] = $lang->name;
  }
  $form['scope']['languages'] = array(
    '#type' => 'checkboxes',
    '#title' => t('Limit to languages'),
    '#options' => $lang_options,
    '#default_value' => $page ? $page['languages'] : array(),
  );
  $form['scope']['manual_filters'] = array(
    '#type' => 'textfield',
    '#title' => t('Manual filter string'),
    '#description' => t('Raw search filter expression, passed through verbatim.'),
    '#default_value' => $page ? $page['manual_filters'] : '',
  );

  // Facets — rebuilt on AJAX.
  $form['facets'] = array(
    '#type' => 'fieldset',
    '#title' => t('Facets'),
    '#prefix' => '<div id="quant-search-facets-wrapper">',
    '#suffix' => '</div>',
  );
  $form['facets']['rows'] = quant_search_page_facet_rows($form_state['facets']);
  $form['facets']['add'] = array(
    '#type' => 'submit',
    '#value' => t('Add facet'),
    '#submit' => array('quant_search_page_form_add_facet'),
    '#ajax' => array('callback' => 'quant_search_page_form_facets_ajax', 'wrapper' => 'quant-search-facets-wrapper'),
    '#limit_validation_errors' => array(),
  );

  // Display options.
  $form['display'] = array('#type' => 'fieldset', '#title' => t('Display'), '#collapsible' => TRUE);
  $d = $page ? $page['display'] : array();
  $form['display']['display_search'] = array(
    '#type' => 'checkbox', '#title' => t('Show search box'),
    '#default_value' => isset($d['results']['display_search']) ? $d['results']['display_search'] : 1,
  );
  $form['display']['display_stats'] = array(
    '#type' => 'checkbox', '#title' => t('Show result stats'),
    '#default_value' => isset($d['results']['display_stats']) ? $d['results']['display_stats'] : 1,
  );
  $form['display']['show_clear_refinements'] = array(
    '#type' => 'checkbox', '#title' => t('Show "clear refinements"'),
    '#default_value' => isset($d['results']['show_clear_refinements']) ? $d['results']['show_clear_refinements'] : 1,
  );
  $form['display']['pagination_enabled'] = array(
    '#type' => 'checkbox', '#title' => t('Enable pagination'),
    '#default_value' => isset($d['pagination']['pagination_enabled']) ? $d['pagination']['pagination_enabled'] : 1,
  );
  $form['display']['per_page'] = array(
    '#type' => 'textfield', '#title' => t('Results per page'), '#size' => 5,
    '#default_value' => isset($d['pagination']['per_page']) ? $d['pagination']['per_page'] : 20,
  );

  $form['actions'] = array('#type' => 'actions');
  $form['actions']['submit'] = array('#type' => 'submit', '#value' => t('Save search page'));
  return $form;
}

/**
 * Builds the facet rows render array from the working facet list.
 *
 * Each facet row exposes: heading, type, taxonomy vocabulary (for taxonomy),
 * date field (for date_range), widget, language, limit, weight, and a remove
 * button. Facet types: taxonomy, content_type, language, date_range, custom.
 */
function quant_search_page_facet_rows($facets) {
  $rows = array();
  $type_options = array(
    'taxonomy' => t('Taxonomy'),
    'content_type' => t('Content type'),
    'language' => t('Language'),
    'date_range' => t('Date range'),
    'custom' => t('Custom attribute'),
  );
  $widget_options = array(
    'checkbox' => t('Checkbox list'),
    'select' => t('Select dropdown'),
    'menu' => t('Menu'),
    'date' => t('Date range inputs'),
  );
  $vocab_options = array();
  foreach (taxonomy_get_vocabularies() as $vocab) {
    $vocab_options[$vocab->machine_name] = $vocab->name;
  }
  foreach ($facets as $i => $facet) {
    $rows[$i] = array(
      'heading' => array('#type' => 'textfield', '#title' => t('Heading'), '#default_value' => isset($facet['heading']) ? $facet['heading'] : ''),
      'type' => array('#type' => 'select', '#title' => t('Type'), '#options' => $type_options, '#default_value' => isset($facet['type']) ? $facet['type'] : 'taxonomy'),
      'vocabulary' => array('#type' => 'select', '#title' => t('Vocabulary'), '#options' => $vocab_options, '#default_value' => isset($facet['vocabulary']) ? $facet['vocabulary'] : ''),
      'date_field' => array('#type' => 'textfield', '#title' => t('Date field'), '#default_value' => isset($facet['date_field']) ? $facet['date_field'] : '', '#description' => t('Indexed date field machine name.')),
      'custom_key' => array('#type' => 'textfield', '#title' => t('Custom attribute'), '#default_value' => isset($facet['custom_key']) ? $facet['custom_key'] : ''),
      'widget' => array('#type' => 'select', '#title' => t('Widget'), '#options' => $widget_options, '#default_value' => isset($facet['widget']) ? $facet['widget'] : 'checkbox'),
      'language' => array('#type' => 'textfield', '#title' => t('Language'), '#size' => 6, '#default_value' => isset($facet['language']) ? $facet['language'] : 'en'),
      'limit' => array('#type' => 'textfield', '#title' => t('Limit'), '#size' => 4, '#default_value' => isset($facet['limit']) ? $facet['limit'] : 10),
      'weight' => array('#type' => 'weight', '#title' => t('Weight'), '#default_value' => isset($facet['weight']) ? $facet['weight'] : 0),
      'remove' => array(
        '#type' => 'submit',
        '#name' => 'remove_facet_' . $i,
        '#value' => t('Remove'),
        '#submit' => array('quant_search_page_form_remove_facet'),
        '#ajax' => array('callback' => 'quant_search_page_form_facets_ajax', 'wrapper' => 'quant-search-facets-wrapper'),
        '#limit_validation_errors' => array(),
        '#facet_index' => $i,
      ),
    );
  }
  return $rows;
}

/**
 * AJAX callback: returns the rebuilt facets fieldset.
 */
function quant_search_page_form_facets_ajax($form, &$form_state) {
  return $form['facets'];
}

/**
 * Submit: append an empty facet row.
 */
function quant_search_page_form_add_facet($form, &$form_state) {
  $form_state['facets'] = quant_search_page_collect_facets($form_state);
  $form_state['facets'][] = array();
  $form_state['rebuild'] = TRUE;
}

/**
 * Submit: remove a facet row.
 */
function quant_search_page_form_remove_facet($form, &$form_state) {
  $form_state['facets'] = quant_search_page_collect_facets($form_state);
  $index = $form_state['triggering_element']['#facet_index'];
  unset($form_state['facets'][$index]);
  $form_state['facets'] = array_values($form_state['facets']);
  $form_state['rebuild'] = TRUE;
}

/**
 * Reads the current facet values out of submitted form input.
 */
function quant_search_page_collect_facets($form_state) {
  $facets = array();
  if (!empty($form_state['values']['facets']['rows'])) {
    foreach ($form_state['values']['facets']['rows'] as $row) {
      $facets[] = array(
        'heading' => $row['heading'],
        'type' => $row['type'],
        'vocabulary' => $row['vocabulary'],
        'date_field' => $row['date_field'],
        'custom_key' => $row['custom_key'],
        'widget' => $row['widget'],
        'language' => $row['language'],
        'limit' => (int) $row['limit'],
        'weight' => (int) $row['weight'],
      );
    }
  }
  return $facets;
}

/**
 * Submit handler: persists the search page.
 */
function quant_search_page_form_submit($form, &$form_state) {
  module_load_include('inc', 'quant_search', 'quant_search.api');
  $v = $form_state['values'];
  $facets = quant_search_page_collect_facets($form_state);
  usort($facets, function ($a, $b) {
    return $a['weight'] - $b['weight'];
  });
  $page = array(
    'id' => $form_state['page_id'],
    'machine_name' => $v['machine_name'],
    'label' => $v['label'],
    'status' => $v['status'],
    'route' => trim($v['route'], '/ '),
    'expose_block' => $v['expose_block'],
    'title' => $v['title'],
    'description' => $v['description'],
    'languages' => array_values(array_filter($v['scope']['languages'])),
    'bundles' => array_values(array_filter($v['scope']['bundles'])),
    'manual_filters' => $v['scope']['manual_filters'],
    'facets' => $facets,
    'display' => array(
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
  );
  quant_search_page_save($page);

  // Register facet keys with the backend.
  module_load_include('inc', 'quant_search', 'quant_search.theme');
  $keys = array();
  foreach (quant_search_compute_facet_keys($facets) as $f) {
    $keys[] = $f['facet_key'];
  }
  quant_search_api_add_facets($keys);

  // Rebuild the menu so the route registers, and flush block info.
  variable_set('menu_rebuild_needed', TRUE);
  cache_clear_all('*', 'cache_block', TRUE);

  drupal_set_message(t('Search page saved.'));
  $form_state['redirect'] = 'admin/config/services/quant/search/pages';
}

/**
 * Form: confirm deleting a search page.
 */
function quant_search_page_delete_form($form, &$form_state, $id) {
  $page = quant_search_page_load($id);
  if (!$page) {
    drupal_not_found();
    drupal_exit();
  }
  $form_state['page_id'] = $id;
  return confirm_form(
    array(),
    t('Delete search page %label?', array('%label' => $page['label'])),
    'admin/config/services/quant/search/pages',
    t('This cannot be undone.'),
    t('Delete'),
    t('Cancel')
  );
}

/**
 * Submit handler: deletes the search page.
 */
function quant_search_page_delete_form_submit($form, &$form_state) {
  quant_search_page_delete($form_state['page_id']);
  variable_set('menu_rebuild_needed', TRUE);
  drupal_set_message(t('Search page deleted.'));
  $form_state['redirect'] = 'admin/config/services/quant/search/pages';
}
```

> Note: `quant_search_compute_facet_keys()` is defined in Task 9 (`quant_search.theme.inc`). The submit handler `module_load_include`s it before use.

- [ ] **Step 4: Verify syntax**

```bash
php -l modules/quant_search/quant_search.pages.inc
php -l modules/quant_search/quant_search.module
```

Expected: "No syntax errors detected".

- [ ] **Step 5: Verify the form works**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
```

Then in the browser as admin: open `/admin/config/services/quant/search/pages`, click "Add search page", create a page (label "Test search", route `test-search`, add one taxonomy facet via "Add facet", save). Confirm it appears in the list. Then verify storage:

```bash
docker compose exec -T -w /opt/drupal drupal drush ev \
  '$p=quant_search_page_load_by_name("test_search"); print_r($p ? array("route"=>$p["route"],"facets"=>count($p["facets"])) : "NOT FOUND");'
```

Expected: prints the route and facet count. (Adjust the machine name if it differs.)

- [ ] **Step 6: Commit**

```bash
git add modules/quant_search/quant_search.module modules/quant_search/quant_search.pages.inc
git commit -m "feat(quant_search): add search-page management UI"
```

---

### Task 9: Search-page rendering and routing

**Files:**
- Create: `modules/quant_search/quant_search.theme.inc`
- Create: `modules/quant_search/templates/quant-search-page.tpl.php`
- Create: `modules/quant_search/css/quant-search.css`
- Modify: `modules/quant_search/quant_search.module` (`hook_menu` route enumeration, `hook_theme`, load include)

- [ ] **Step 1: Write `quant_search.theme.inc`**

This file holds the facet-key computation, the filter-string builder, and the shared render builder used by both the route and the block.

```php
<?php

/**
 * @file
 * Quant Search: rendering helpers shared by routes and blocks.
 */

/**
 * Computes the index attribute name and container id for each facet.
 *
 * @param array $facets
 *   Facet definitions from a search page.
 *
 * @return array
 *   Each facet augmented with 'facet_key' (index attribute) and
 *   'facet_container' (unique id suffix).
 */
function quant_search_compute_facet_keys($facets) {
  $out = array();
  foreach (array_values($facets) as $i => $facet) {
    $lang = !empty($facet['language']) ? $facet['language'] : 'en';
    switch ($facet['type']) {
      case 'taxonomy':
        $key = $facet['vocabulary'] . '_' . $lang;
        break;

      case 'content_type':
        $key = 'content_type_' . $lang;
        break;

      case 'language':
        $key = 'language_' . $lang;
        break;

      case 'date_range':
        $key = $facet['date_field'];
        break;

      case 'custom':
      default:
        $key = $facet['custom_key'];
        break;
    }
    $facet['facet_key'] = $key;
    $facet['facet_container'] = $key . '_' . $i;
    $out[] = $facet;
  }
  return $out;
}

/**
 * Builds the search-backend filter string for a page.
 */
function quant_search_build_filters($page) {
  $clauses = array();
  if (!empty($page['languages'])) {
    $parts = array();
    foreach ($page['languages'] as $lang) {
      $parts[] = "lang_code:'" . $lang . "'";
    }
    $clauses[] = '(' . implode(' OR ', $parts) . ')';
  }
  if (!empty($page['bundles'])) {
    $parts = array();
    foreach ($page['bundles'] as $bundle) {
      $parts[] = "content_type:'" . $bundle . "'";
    }
    $clauses[] = '(' . implode(' OR ', $parts) . ')';
  }
  if (!empty($page['manual_filters'])) {
    $clauses[] = $page['manual_filters'];
  }
  return implode(' AND ', $clauses);
}

/**
 * Builds the render array for a search page (route or block).
 *
 * @param array $page
 *   A loaded search page.
 *
 * @return array
 *   A renderable array.
 */
function quant_search_render($page) {
  module_load_include('inc', 'quant_search', 'quant_search.api');
  $project = quant_search_api_project();
  if (!$project || empty($project->config->search_enabled)) {
    return array('#markup' => '<p>' . t('Search is not currently available.') . '</p>');
  }
  $index = $project->config->search_index;
  $facets = quant_search_compute_facet_keys($page['facets']);
  $instance = $page['machine_name'];

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

  $module_path = drupal_get_path('module', 'quant_search');
  return array(
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
}
```

- [ ] **Step 2: Write `templates/quant-search-page.tpl.php`**

All container ids are prefixed with the instance machine name so a page works as both route and block, and multiple search blocks coexist.

```php
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
            <div class="quant-search-facet-heading"><?php print check_plain($facet['facet_heading'] = isset($facet['heading']) ? $facet['heading'] : ''); ?></div>
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
```

- [ ] **Step 3: Write a minimal `css/quant-search.css`**

```css
.quant-search-grid { display: flex; gap: 2rem; }
.quant-search-facets { flex: 0 0 240px; }
.quant-search-results { flex: 1 1 auto; }
.quant-search-facet { margin-bottom: 1.5rem; }
.quant-search-facet-heading { font-weight: bold; margin-bottom: .5rem; }
.quant-search-date-range { display: flex; gap: .5rem; }
.quant-search-date-range label { display: flex; flex-direction: column; font-size: .85em; }
@media (max-width: 700px) { .quant-search-grid { flex-direction: column; } }
```

- [ ] **Step 4: Add `hook_theme`, route enumeration, and the page callback to `quant_search.module`**

Add `hook_theme`:

```php
/**
 * Implements hook_theme().
 */
function quant_search_theme($existing, $type, $theme, $path) {
  return array(
    'quant_search_page' => array(
      'variables' => array('page' => NULL, 'facets' => array(), 'instance' => ''),
      'template' => 'templates/quant-search-page',
    ),
  );
}
```

Add the page callback:

```php
/**
 * Page callback: renders a search page by machine name.
 */
function quant_search_view_page($machine_name) {
  module_load_include('inc', 'quant_search', 'quant_search.pages');
  module_load_include('inc', 'quant_search', 'quant_search.theme');
  $page = quant_search_page_load_by_name($machine_name);
  if (!$page || !$page['status']) {
    return MENU_NOT_FOUND;
  }
  if (!empty($page['title'])) {
    drupal_set_title($page['title']);
  }
  return quant_search_render($page);
}
```

In `quant_search_menu()`, add route enumeration before `return $items;`:

```php
  module_load_include('inc', 'quant_search', 'quant_search.pages');
  foreach (quant_search_page_load_all(TRUE) as $page) {
    if ($page['route'] !== '') {
      $items[$page['route']] = array(
        'title' => $page['title'] !== '' ? $page['title'] : $page['label'],
        'page callback' => 'quant_search_view_page',
        'page arguments' => array($page['machine_name']),
        'access arguments' => array('access content'),
        'type' => MENU_NORMAL_ITEM,
      );
    }
  }
```

Also load the theme include — add to the `module_load_include` block at the top of `quant_search.module`:

```php
module_load_include('inc', 'quant_search', 'quant_search.theme');
```

- [ ] **Step 5: Verify syntax**

```bash
php -l modules/quant_search/quant_search.theme.inc
php -l modules/quant_search/quant_search.module
php -l modules/quant_search/templates/quant-search-page.tpl.php
```

Expected: "No syntax errors detected".

- [ ] **Step 6: Verify the route renders the shell**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
curl -s "http://localhost:8084/test-search" | grep -o 'id="quant-search-[^"]*"'
curl -s "http://localhost:8084/test-search" | grep -c 'qs-.*-hits'
```

Expected: the first command prints `id="quant-search-test_search"` (the instance wrapper); the second prints `1` (the hits container is present). Use the route you created in Task 8. If search is enabled on the project, the page also includes the InstantSearch JS/settings; if not, it shows the "Search is not currently available" message — both confirm routing works.

- [ ] **Step 7: Commit**

```bash
git add modules/quant_search/quant_search.theme.inc modules/quant_search/templates modules/quant_search/css modules/quant_search/quant_search.module
git commit -m "feat(quant_search): render search pages and register routes"
```

---

### Task 10: InstantSearch frontend with date-range facet

**Files:**
- Create: `modules/quant_search/js/quant-search.js`

- [ ] **Step 1: Write `js/quant-search.js`**

Ported from the D9 `quant-search.js`, with two changes: it iterates **all instances** under `Drupal.settings.quantSearch` (for block embedding), scoping every container id with the instance prefix; and it adds a **date-range** widget using InstantSearch's `connectRange` connector with native `<input type="date">` fields, mapping dates to the numeric-timestamp attribute.

```js
/**
 * @file
 * Quant Search — InstantSearch.js initialisation.
 *
 * Renders one InstantSearch instance per search page in
 * Drupal.settings.quantSearch. Container ids are prefixed "qs-{instance}-".
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.quantSearch = {
    attach: function (context, settings) {
      if (!settings.quantSearch || typeof instantsearch === 'undefined') {
        return;
      }
      Object.keys(settings.quantSearch).forEach(function (instance) {
        var cfg = settings.quantSearch[instance];
        var root = document.getElementById('quant-search-' + instance);
        if (!root || root.getAttribute('data-qs-init')) {
          return;
        }
        root.setAttribute('data-qs-init', '1');
        Drupal.quantSearch.build(cfg);
      });
    }
  };

  Drupal.quantSearch = Drupal.quantSearch || {};

  /**
   * Builds and starts one InstantSearch instance.
   */
  Drupal.quantSearch.build = function (cfg) {
    var id = function (suffix) { return '#qs-' + cfg.instance + '-' + suffix; };

    var search = instantsearch({
      indexName: cfg.index,
      searchClient: algoliasearch(cfg.app_id, cfg.read_key),
      routing: false
    });

    var widgets = [];
    var display = cfg.display || {};
    var results = display.results || {};
    var pagination = display.pagination || {};

    if (results.display_search) {
      widgets.push(instantsearch.widgets.searchBox({ container: id('searchbox') }));
    }
    if (results.display_stats) {
      widgets.push(instantsearch.widgets.stats({ container: id('stats') }));
    }
    if (pagination.pagination_enabled) {
      widgets.push(instantsearch.widgets.pagination({ container: id('pagination') }));
    }
    if (results.show_clear_refinements) {
      widgets.push(instantsearch.widgets.clearRefinements({ container: id('clear') }));
    }

    // Facets.
    (cfg.facets || []).forEach(function (facet) {
      var container = id('facet-' + facet.facet_container);
      switch (facet.widget) {
        case 'checkbox':
          widgets.push(instantsearch.widgets.refinementList({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;

        case 'select':
          widgets.push(instantsearch.widgets.menuSelect({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;

        case 'menu':
          widgets.push(instantsearch.widgets.menu({
            container: container, attribute: facet.facet_key, limit: facet.limit || 10
          }));
          break;

        case 'date':
          widgets.push(Drupal.quantSearch.dateRangeWidget(container, facet.facet_key));
          break;
      }
    });

    widgets.push(instantsearch.widgets.configure({
      filters: cfg.filters || '',
      hitsPerPage: pagination.per_page || 20,
      attributesToSnippet: ['summary:50']
    }));

    widgets.push(instantsearch.widgets.hits({
      container: id('hits'),
      templates: {
        empty: '<p>' + Drupal.t('No results found.') + '</p>',
        item: function (hit) {
          var img = hit.image ? '<img src="' + hit.image + '" alt="" class="qs-hit-image" />' : '';
          var summary = hit.summary ? '<div class="qs-hit-summary">' + hit.summary + '</div>' : '';
          return '<a class="qs-hit" href="' + (hit.url || '#') + '">' + img +
            '<h4 class="qs-hit-title">' + (hit.title || '') + '</h4>' + summary + '</a>';
        }
      }
    }));

    search.addWidgets(widgets);
    search.start();
  };

  /**
   * A date-range facet widget built on connectRange + native date inputs.
   *
   * The indexed attribute is a numeric Unix timestamp (or array of them for
   * recurring dates); the search backend matches a numeric range against any
   * value in the array.
   */
  Drupal.quantSearch.dateRangeWidget = function (container, attribute) {
    var render = function (renderOptions, isFirstRender) {
      var node = document.querySelector(container);
      if (!node) { return; }
      if (isFirstRender) {
        node.innerHTML =
          '<div class="quant-search-date-range">' +
          '<label>' + Drupal.t('From') + '<input type="date" class="qs-date-from" /></label>' +
          '<label>' + Drupal.t('To') + '<input type="date" class="qs-date-to" /></label>' +
          '</div>';
        var apply = function () {
          var from = node.querySelector('.qs-date-from').value;
          var to = node.querySelector('.qs-date-to').value;
          var min = from ? Math.floor(new Date(from + 'T00:00:00').getTime() / 1000) : undefined;
          var max = to ? Math.floor(new Date(to + 'T23:59:59').getTime() / 1000) : undefined;
          renderOptions.refine([min, max]);
        };
        node.querySelector('.qs-date-from').addEventListener('change', apply);
        node.querySelector('.qs-date-to').addEventListener('change', apply);
      }
    };
    return instantsearch.connectors.connectRange(render)({ attribute: attribute });
  };

}(Drupal));
```

- [ ] **Step 2: Verify JS syntax**

```bash
node --check modules/quant_search/js/quant-search.js
```

Expected: no output (valid JS). If `node` is unavailable, skip — the browser check in Step 3 covers it.

- [ ] **Step 3: Verify in the browser**

This requires the `wwwslvvicgovau-dev` project to have hosted search enabled and at least some content indexed (run a batch re-index from Task 6 first). Then load the search page route in a browser:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
```

Open `http://localhost:8084/test-search`. Expected: the InstantSearch search box, hits, and any configured facets render and respond. If a date-range facet is configured, two date inputs appear and filter the results. If the project does not have search enabled, the page shows "Search is not currently available" — record that as a blocked verification to revisit once search is provisioned, and confirm instead that the JS file loads without console errors.

- [ ] **Step 4: Commit**

```bash
git add modules/quant_search/js/quant-search.js
git commit -m "feat(quant_search): add InstantSearch frontend with date-range facet"
```

---

### Task 11: Search pages as blocks

**Files:**
- Modify: `modules/quant_search/quant_search.module` (`hook_block_info`, `hook_block_view`)

- [ ] **Step 1: Add block hooks to `quant_search.module`**

```php
/**
 * Implements hook_block_info().
 */
function quant_search_block_info() {
  module_load_include('inc', 'quant_search', 'quant_search.pages');
  $blocks = array();
  foreach (quant_search_page_load_all(TRUE) as $page) {
    if (!empty($page['expose_block'])) {
      $blocks['page_' . $page['machine_name']] = array(
        'info' => t('Quant search: !label', array('!label' => $page['label'])),
        'cache' => DRUPAL_NO_CACHE,
      );
    }
  }
  return $blocks;
}

/**
 * Implements hook_block_view().
 */
function quant_search_block_view($delta = '') {
  if (strpos($delta, 'page_') !== 0) {
    return NULL;
  }
  module_load_include('inc', 'quant_search', 'quant_search.pages');
  module_load_include('inc', 'quant_search', 'quant_search.theme');
  $page = quant_search_page_load_by_name(substr($delta, strlen('page_')));
  if (!$page || !$page['status']) {
    return NULL;
  }
  return array(
    'subject' => '',
    'content' => quant_search_render($page),
  );
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l modules/quant_search/quant_search.module
```

Expected: "No syntax errors detected".

- [ ] **Step 3: Verify the block exists and renders**

Edit the `test-search` page (Task 8) and tick "Also expose this search page as a block", save. Then:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
docker compose exec -T -w /opt/drupal drupal drush ev \
  '$b=module_invoke("quant_search","block_info"); print_r(array_keys($b));'
```

Expected: prints an array including `page_test_search`. Then place that block in a region via `/admin/structure/block` and load a page showing it — confirm the search renders inside the block with instance-prefixed container ids (`qs-test_search-hits` etc.), distinct from the route's.

- [ ] **Step 4: Commit**

```bash
git add modules/quant_search/quant_search.module
git commit -m "feat(quant_search): expose search pages as blocks"
```

**Phase 2 complete:** faceted search pages can be created, served at routes, and embedded as blocks.

---

# Phase 3 — Autocomplete and admin

### Task 12: Autocomplete block

**Files:**
- Create: `modules/quant_search/js/quant-autocomplete.js`
- Modify: `modules/quant_search/quant_search.module` (`hook_block_info`/`view`/`configure` for the autocomplete block)

- [ ] **Step 1: Write `js/quant-autocomplete.js`**

Ported from the D9 autocomplete. Uses `@algolia/autocomplete-js` + `algoliasearch` from CDN; on submit it navigates to the linked search page's route.

```js
/**
 * @file
 * Quant Search — autocomplete block.
 */
(function (Drupal) {
  'use strict';

  Drupal.behaviors.quantSearchAutocomplete = {
    attach: function (context, settings) {
      var cfg = settings.quantSearchAutocomplete;
      if (!cfg || typeof algoliasearch === 'undefined' || !window['@algolia/autocomplete-js']) {
        return;
      }
      var mount = document.getElementById('quant-search-autocomplete');
      if (!mount || mount.getAttribute('data-qs-init')) {
        return;
      }
      mount.setAttribute('data-qs-init', '1');

      var autocomplete = window['@algolia/autocomplete-js'].autocomplete;
      var getAlgoliaResults = window['@algolia/autocomplete-js'].getAlgoliaResults;
      var client = algoliasearch(cfg.app_id, cfg.read_key);

      autocomplete({
        container: '#quant-search-autocomplete',
        placeholder: cfg.placeholder || Drupal.t('Search'),
        detachedMediaQuery: 'none',
        onSubmit: function (params) {
          window.location.href = cfg.search_path + '?q=' + encodeURIComponent(params.state.query);
        },
        getSources: function () {
          return [{
            sourceId: cfg.index,
            getItemUrl: function (params) { return params.item.url; },
            getItems: function (params) {
              return getAlgoliaResults({
                searchClient: client,
                queries: [{
                  indexName: cfg.index,
                  query: params.query,
                  params: { filters: cfg.filters || '' }
                }]
              });
            },
            onSelect: function (params) { window.location.assign(params.item.url); },
            templates: {
              item: function (params) {
                var summary = (cfg.show_summary && params.item.summary)
                  ? params.html('<div class="qs-ac-summary">' + params.item.summary + '</div>') : '';
                return params.html('<div><strong>' + (params.item.title || '') + '</strong>' + summary + '</div>');
              }
            }
          }];
        }
      });
    }
  };

}(Drupal));
```

- [ ] **Step 2: Add the autocomplete block to `quant_search.module`**

Extend the existing `quant_search_block_info()` to add an `autocomplete` block, and `quant_search_block_view()` to render it; add `hook_block_configure` and `hook_block_save` for its settings.

In `quant_search_block_info()`, add to the `$blocks` array before `return $blocks;`:

```php
  $blocks['autocomplete'] = array(
    'info' => t('Quant search: autocomplete'),
    'cache' => DRUPAL_NO_CACHE,
  );
```

In `quant_search_block_view()`, add before the existing `page_` handling (i.e. at the top of the function body):

```php
  if ($delta === 'autocomplete') {
    module_load_include('inc', 'quant_search', 'quant_search.pages');
    module_load_include('inc', 'quant_search', 'quant_search.theme');
    module_load_include('inc', 'quant_search', 'quant_search.api');
    $page_name = variable_get('quant_search_autocomplete_page', '');
    $page = $page_name ? quant_search_page_load_by_name($page_name) : FALSE;
    $project = quant_search_api_project();
    if (!$page || !$project || empty($project->config->search_enabled)) {
      return NULL;
    }
    $index = $project->config->search_index;
    $module_path = drupal_get_path('module', 'quant_search');
    return array(
      'subject' => '',
      'content' => array(
        '#markup' => '<div id="quant-search-autocomplete"></div>',
        '#attached' => array(
          'js' => array(
            array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/algoliasearch@4/dist/algoliasearch-lite.umd.js'),
            array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/@algolia/autocomplete-js'),
            $module_path . '/js/quant-autocomplete.js',
            array('type' => 'setting', 'data' => array('quantSearchAutocomplete' => array(
              'app_id' => $index->algolia_application_id,
              'read_key' => $index->algolia_read_key,
              'index' => $index->algolia_index,
              'placeholder' => variable_get('quant_search_autocomplete_placeholder', t('Search')),
              'show_summary' => (bool) variable_get('quant_search_autocomplete_summary', FALSE),
              'search_path' => '/' . $page['route'],
              'filters' => quant_search_build_filters($page),
            ))),
          ),
          'css' => array(
            array('type' => 'external', 'data' => 'https://cdn.jsdelivr.net/npm/@algolia/autocomplete-theme-classic'),
          ),
        ),
      ),
    );
  }
```

Add the configure and save hooks:

```php
/**
 * Implements hook_block_configure().
 */
function quant_search_block_configure($delta = '') {
  $form = array();
  if ($delta === 'autocomplete') {
    module_load_include('inc', 'quant_search', 'quant_search.pages');
    $options = array('' => t('- Select -'));
    foreach (quant_search_page_load_all(TRUE) as $page) {
      if ($page['route'] !== '') {
        $options[$page['machine_name']] = $page['label'];
      }
    }
    $form['quant_search_autocomplete_page'] = array(
      '#type' => 'select',
      '#title' => t('Search page to submit to'),
      '#options' => $options,
      '#default_value' => variable_get('quant_search_autocomplete_page', ''),
    );
    $form['quant_search_autocomplete_placeholder'] = array(
      '#type' => 'textfield',
      '#title' => t('Placeholder text'),
      '#default_value' => variable_get('quant_search_autocomplete_placeholder', t('Search')),
    );
    $form['quant_search_autocomplete_summary'] = array(
      '#type' => 'checkbox',
      '#title' => t('Show result summaries'),
      '#default_value' => variable_get('quant_search_autocomplete_summary', FALSE),
    );
  }
  return $form;
}

/**
 * Implements hook_block_save().
 */
function quant_search_block_save($delta = '', $edit = array()) {
  if ($delta === 'autocomplete') {
    variable_set('quant_search_autocomplete_page', $edit['quant_search_autocomplete_page']);
    variable_set('quant_search_autocomplete_placeholder', $edit['quant_search_autocomplete_placeholder']);
    variable_set('quant_search_autocomplete_summary', (bool) $edit['quant_search_autocomplete_summary']);
  }
}
```

Also extend `quant_search_uninstall()` in `quant_search.install` — add:

```php
  variable_del('quant_search_autocomplete_page');
  variable_del('quant_search_autocomplete_placeholder');
  variable_del('quant_search_autocomplete_summary');
```

- [ ] **Step 3: Verify syntax**

```bash
php -l modules/quant_search/quant_search.module
php -l modules/quant_search/quant_search.install
node --check modules/quant_search/js/quant-autocomplete.js
```

Expected: "No syntax errors detected" / no JS errors.

- [ ] **Step 4: Verify the autocomplete block**

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
docker compose exec -T -w /opt/drupal drupal drush ev \
  '$b=module_invoke("quant_search","block_info"); print_r(array_keys($b));'
```

Expected: the block list includes `autocomplete`. Then in `/admin/structure/block`, configure the autocomplete block (pick the `test-search` page, set a placeholder), place it in a region, and confirm the autocomplete input renders and (if search is enabled) returns suggestions.

- [ ] **Step 5: Commit**

```bash
git add modules/quant_search/quant_search.module modules/quant_search/quant_search.install modules/quant_search/js/quant-autocomplete.js
git commit -m "feat(quant_search): add autocomplete block"
```

---

### Task 13: Admin overview / status page

**Files:**
- Modify: `modules/quant_search/quant_search.admin.inc` (`quant_search_admin_overview`)

- [ ] **Step 1: Replace `quant_search_admin_overview()` in `quant_search.admin.inc`**

Replace the stub from Task 3 with a real status page showing index stats and a search-enabled check.

```php
/**
 * Page callback: Quant Search overview / status.
 */
function quant_search_admin_overview() {
  module_load_include('inc', 'quant_search', 'quant_search.api');
  module_load_include('inc', 'quant_search', 'quant_search.pages');

  $project = quant_search_api_project();
  $rows = array();

  if (!$project) {
    $rows[] = array(t('Connection'), t('Could not reach the Quant API. Check the Quant API settings.'));
  }
  else {
    $enabled = !empty($project->config->search_enabled);
    $rows[] = array(t('Hosted search enabled'), $enabled ? t('Yes') : t('No — enable it in the Quant dashboard.'));
    if ($enabled) {
      $stats = quant_search_api_stats();
      if ($stats && isset($stats->index)) {
        $rows[] = array(t('Index'), check_plain(is_scalar($stats->index) ? $stats->index : drupal_json_encode($stats->index)));
      }
    }
  }
  $rows[] = array(t('Search pages'), count(quant_search_page_load_all()));

  return array(
    'status' => array(
      '#theme' => 'table',
      '#header' => array(t('Item'), t('Value')),
      '#rows' => $rows,
    ),
    'links' => array(
      '#markup' => '<p>' . l(t('Manage search pages'), 'admin/config/services/quant/search/pages')
        . ' · ' . l(t('Configure indexing'), 'admin/config/services/quant/search/entities')
        . ' · ' . l(t('Re-index'), 'admin/config/services/quant/search/index') . '</p>',
    ),
  );
}
```

- [ ] **Step 2: Verify syntax and the page**

```bash
php -l modules/quant_search/quant_search.admin.inc
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8084/admin/config/services/quant/search"
```

Expected: no syntax error; the overview page returns `200` for an admin session (or `403` anonymously). Viewed as admin, it shows the connection status, the hosted-search-enabled flag, and the search-page count.

- [ ] **Step 3: Commit**

```bash
git add modules/quant_search/quant_search.admin.inc
git commit -m "feat(quant_search): add admin overview/status page"
```

---

### Task 14: End-to-end verification and README

**Files:**
- Create: `modules/quant_search/README.md`

- [ ] **Step 1: Write `modules/quant_search/README.md`**

```markdown
# Quant Search (Drupal 7)

Indexes Drupal content into Quant's hosted search and provides faceted search
pages — served at their own URL and/or embedded as blocks — plus an
autocomplete block.

## Requirements

- The `quant` and `quant_api` modules, configured with valid Quant API
  credentials (`admin/config/services/quant/api`).
- The `token` module.
- The Quant project must have **hosted search enabled**.

## Setup

1. Enable the module: `drush en quant_search`.
2. Configure indexing at `admin/config/services/quant/search/entities` — choose
   content types, the body view mode, token patterns, and any date fields.
3. Re-index existing content at `admin/config/services/quant/search/index`.
   New and updated content is indexed automatically on save.
4. Create search pages at `admin/config/services/quant/search/pages` — set a
   route and/or expose the page as a block, and add facets (taxonomy, content
   type, language, date range, or custom).

## Known limitations

- The Quant search API exposes no single-record delete. Unpublished or deleted
  content is removed from the index by the next full re-index, not immediately.
```

- [ ] **Step 2: Full end-to-end verification on the SLV site**

With `quant_api` configured for the `wwwslvvicgovau-dev` project:

```bash
cd /Users/stuart/apps/slv-migration/www-slv-qc
docker compose exec -T -w /opt/drupal drupal drush cc all
# 1. Indexing config exists.
docker compose exec -T -w /opt/drupal drupal drush vget quant_search_entities
# 2. Run a re-index (via the batch op directly).
docker compose exec -T -w /opt/drupal drupal drush ev \
  'module_load_include("inc","quant_search","quant_search.admin");
   module_load_include("inc","quant_search","quant_search.index");
   module_load_include("inc","quant_search","quant_search.api");
   $c=variable_get("quant_search_entities",array()); $b=isset($c["node"]["bundles"])?$c["node"]["bundles"]:array();
   if($b){$nids=db_select("node","n")->fields("n",array("nid"))->condition("status",1)->condition("type",$b,"IN")->range(0,50)->execute()->fetchCol();
   $ctx=array(); quant_search_index_batch_op($nids,$ctx); echo "indexed: ".(isset($ctx["results"]["count"])?$ctx["results"]["count"]:0)."\n";}
   else{echo "no bundles configured\n";}'
# 3. A search page route responds.
curl -s -o /dev/null -w "search page route: %{http_code}\n" "http://localhost:8084/test-search"
# 4. Overview page.
docker compose exec -T -w /opt/drupal drupal drush ev \
  'module_load_include("inc","quant_search","quant_search.api"); $p=quant_search_api_project(); echo "search_enabled: ".($p&&!empty($p->config->search_enabled)?"yes":"no")."\n";'
```

Expected: indexing config prints; the batch op reports an indexed count > 0; the route returns `200`; the overview reports the search-enabled state. In a browser, load `/test-search` and confirm InstantSearch renders results and facets (including a date-range facet if configured). Record any step that cannot pass because hosted search is not enabled on the project, and confirm the rest.

- [ ] **Step 3: Final syntax sweep**

```bash
for f in modules/quant_search/*.module modules/quant_search/*.inc modules/quant_search/*.install; do php -l "$f"; done
```

Expected: "No syntax errors detected" for every file.

- [ ] **Step 4: Commit**

```bash
git add modules/quant_search/README.md
git commit -m "docs(quant_search): add module README"
```

**Phase 3 complete:** the module is feature-complete — indexing, faceted search pages (route + block), date facets, and autocomplete.

---

## Risks and notes

- **Hosted search must be enabled** on the `wwwslvvicgovau-dev` Quant project for end-to-end verification. If it is not, the Drupal-side plumbing (forms, record generation, routing, block info, settings injection) is still fully verifiable; only the live InstantSearch queries are blocked. Flag this to the project owner early.
- **No single-record delete** in the Quant search API. The plan reconciles deletes via re-index. If a per-record delete endpoint is confirmed against the live API, add `quant_search_api_delete_record()` and a `hook_node_delete` (see the note in Task 5).
- **CDN-loaded JS** — InstantSearch.js and algoliasearch are loaded from jsdelivr, matching the D9 module. If the deployment must avoid third-party CDNs, a follow-up can vendor these into the module.
- **`algolia_*` field names** in the Quant API response are a legacy naming artifact; the backend is Typesense behind an Algolia-compatible API. The module maps them to neutral `app_id` / `read_key` / `index` keys internally.
- **Recurring-event dates** index as arrays of timestamps; a date-range refinement matches if any timestamp falls in range. Confirm this matches the live `slv.vic.gov.au/whats-on` expectation when SLV's real pages are built (a separate follow-up).

## Self-review

- **Spec coverage:** module skeleton + schema (Task 1); API client (Task 2); per-entity index config (Task 3); record generation incl. date→timestamp (Task 4); real-time indexing hooks (Task 5); batch re-index + clear (Task 6); search-page table + CRUD (Task 7); search-page admin form + list, facets incl. `date_range` (Task 8); rendering + `hook_menu` routing + theme + tpl.php (Task 9); InstantSearch JS, instance-scoped, with date-range widget (Task 10); block embedding (Task 11); autocomplete block (Task 12); admin overview/status + permissions [`hook_permission` in Task 1, menu in Task 3] (Task 13); testing + README (Task 14). Every spec section maps to a task.
- **Placeholder scan:** no TBD/TODO; all code is given in full. The Task 5 delete note and Task 10 search-enabled note are explicit, scoped contingencies, not placeholders.
- **Type/name consistency:** `quant_search_page` table and its columns; `quant_search_entities` variable; the search-page array shape (`machine_name`, `route`, `expose_block`, `facets`, `display`) is consistent across `quant_search.pages.inc`, `quant_search.theme.inc`, `quant_search.module`, and the template; `quant_search_compute_facet_keys()` / `quant_search_build_filters()` / `quant_search_render()` / `quant_search_generate_record()` / `quant_search_api_*()` names are used consistently between definition and call sites; container ids use the `qs-{instance}-` prefix in both the template and `quant-search.js`; `Drupal.settings.quantSearch` is a map keyed by instance in both the renderer and the JS.
