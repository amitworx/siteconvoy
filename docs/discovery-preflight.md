# Read-Only Source Discovery & Preflight Contracts

> [!NOTE]
> **Task Reference:** `SITECONVOY-M1-002`
> **Status:** IMPLEMENTED (`0.1.0-dev`) &mdash; READ-ONLY SOURCE INSPECTION ONLY
> This document defines the PHP contracts, capability guards, WordPress table classification rules, and preflight report semantics introduced in `SITECONVOY-M1-002`. These components are strictly **read-only**: they do not export packages, import data, write options, modify database schemas, or expose admin/REST endpoints.

---

## 1. Subsystem Overview & Architecture

The `SITECONVOY-M1-002` subsystem provides deterministic source inspection and preflight validation across five classes loaded under `includes/`:

| Class | File | Responsibility |
|---|---|---|
| `SiteConvoy\Contracts\MigrationMode` | `includes/Contracts/MigrationMode.php` | Canonical identifiers and source/site-ID/target topology rules for all four migration operations. |
| `SiteConvoy\Contracts\PreflightFinding` | `includes/Contracts/PreflightFinding.php` | Value object representing a single preflight finding (`id`, `severity`, `message`, `context`). Implements `\JsonSerializable`. |
| `SiteConvoy\Contracts\PreflightReport` | `includes/Contracts/PreflightReport.php` | Value object representing a complete read-only preflight outcome. Enforces fail-closed invariants so `can_proceed_to_next_stage` is never `true` when blockers exist or when the destination environment is unverified. |
| `SiteConvoy\Discovery\SourceDiscoveryService` | `includes/Discovery/SourceDiscoveryService.php` | Read-only WordPress source inspector querying `$wpdb`, `get_sites()`, `get_site()`, `get_main_site_id()`, and `wp_upload_dir()`. Restores blog context via `try / finally`. |
| `SiteConvoy\Preflight\PreflightService` | `includes/Preflight/PreflightService.php` | Authorization gate (`manage_network` on multisite, `manage_options` on standalone) and rule evaluator producing a `PreflightReport`. |

---

## 2. Supported Migration Mode Identifiers

`SiteConvoy\Contracts\MigrationMode` defines four canonical operation modes:

| Constant | Identifier | Source Topology Required | Explicit `source_site_id` Required | Expected Target Type |
|---|---|---|---|---|
| `MigrationMode::SUBSITE_TO_STANDALONE` | `'subsite_to_standalone'` | Multisite (`is_multisite() === true`) | Yes (`> 0`) | `'standalone'` |
| `MigrationMode::SUBSITE_TO_MULTISITE` | `'subsite_to_multisite'` | Multisite (`is_multisite() === true`) | Yes (`> 0`) | `'multisite_subsite'` |
| `MigrationMode::FULL_NETWORK_TO_NEW_ENV` | `'full_network_to_new_env'` | Multisite (`is_multisite() === true`) | No (`null`) | `'multisite_network'` |
| `MigrationMode::STANDALONE_TO_SUBSITE` | `'standalone_to_subsite'` | Standalone (`is_multisite() === false`) | No (`null`) | `'multisite_subsite'` |

---

## 3. Authorization & Security Boundaries

1. **Pre-Discovery Capability Gate**:
   - `PreflightService::run()` checks capabilities **before** calling `SourceDiscoveryService::discover()`:
     - Multisite installation (`is_multisite() === true`): requires `current_user_can( 'manage_network' )`. A single-subsite administrator (`manage_options` without `manage_network`) is denied.
     - Standalone installation (`is_multisite() === false`): requires `current_user_can( 'manage_options' )`.
   - When unauthorized, `PreflightService::run()` immediately returns a `PreflightReport` with:
     - `status = 'unauthorized'`
     - `can_proceed_to_next_stage = false`
     - `source_inventory = null`
     - Finding `SC_AUTH_FORBIDDEN` (`blocker`)
2. **Data Minimization & Zero Secret Exposure**:
   - Discovery and preflight reports never read or serialize database credentials (`DB_PASSWORD`), authentication keys/salts (`AUTH_KEY`, `SECURE_AUTH_KEY`, etc.), user password hashes (`user_pass`), user email addresses (`user_email`), session tokens, or raw option values.
   - Filesystem paths in `filesystem.uploads_relative_basedir` are normalized relative to `ABSPATH` to avoid leaking host filesystem user directories.
3. **Zero Side Effects & Blog Context Restoration**:
   - Neither `SourceDiscoveryService` nor `PreflightService` writes options, transients, cron schedules, or files.
   - When inspecting a target subsite on multisite (`switch_to_blog( $inspect_site_id )`), `SourceDiscoveryService` wraps the inspection in a `try / finally` block calling `restore_current_blog()` so the caller's active blog context is always preserved.

---

## 4. WordPress Core Table Resolution & Classification

During `SITECONVOY-M1-002`, `SourceDiscoveryService` was verified directly against WordPress 6.7 (`wp-includes/class-wpdb.php` and `wp-admin/includes/schema.php`):

1. **`core_blog` (`$wpdb->tables( 'blog', true, $site_id )`)**:
   - `posts`, `comments`, `links`, `options`, `postmeta`, `terms`, `term_taxonomy`, `term_relationships`, `termmeta`, `commentmeta`.
   - On Site ID 1, these use `$wpdb->base_prefix` (e.g., `wp_posts`). On secondary subsites (`$site_id > 1`), these use `$wpdb->get_blog_prefix( $site_id )` (e.g., `wp_2_posts`).
2. **`core_global` (`$wpdb->tables( 'global' )` minus `ms_global`)**:
   - `users`, `usermeta` (`{$wpdb->base_prefix}users`, `{$wpdb->base_prefix}usermeta`).
3. **`core_ms_global` (`$wpdb->tables( 'ms_global', true, $site_id )`)**:
   - `blogs`, `blogmeta` (introduced in WordPress 5.1), `signups`, `site`, `sitemeta`, `registration_log`.
4. **`core_old` (`$wpdb->tables( 'old', true, $site_id )`)**:
   - `categories`, `post2cat`, `link2cat`, and on multisite `sitecategories` (from `wpdb::$old_ms_global_tables`). Each entry records whether the table physically exists in `SHOW TABLES`.
5. **`legacy_multisite`**:
   - Detects legacy pre-3.0 WordPress MU tables not registered in `wpdb::$ms_global_tables`, specifically `{$wpdb->base_prefix}blog_versions`, when physically present in `SHOW TABLES`.
6. **`unknown_custom`**:
   - Physical tables in `SHOW TABLES` that do not belong to any core catalog across any network subsite are classified into:
     - `blog_prefix_matched`: Custom/plugin tables matching the inspected site's prefix (triggers `SC_UNKNOWN_CUSTOM_TABLES_DETECTED`).
     - `base_prefix_matched`: Custom tables matching `$wpdb->base_prefix` (or another subsite's prefix when inspecting a secondary subsite).
     - `unprefixed_other`: Tables in the same database without `$wpdb->base_prefix`.

---

## 5. Paginated Network Site Enumeration

WordPress's `get_sites()` defaults to `'number' => 100`. To ensure networks with more than 100 subsites are never silently truncated during discovery, `SourceDiscoveryService::enumerate_network_sites( int $batch_size = 100 )` queries `get_sites()` in deterministic pages (`'orderby' => 'id'`, `'order' => 'ASC'`, `'number' => $batch_size`, `'offset' => $offset`) until a page returns fewer than `$batch_size` results.

---

## 6. Preflight Status Semantics & Findings Catalog

### 6.1 Report Statuses & `can_proceed_to_next_stage`
- `'unauthorized'`: Caller lacks required WordPress capability (`manage_network` on multisite, `manage_options` on standalone). `source_inventory` is `null`.
- `'blocked'`: One or more `blocker` findings were detected.
- `'source_inspected'`: Source inspection completed with zero `blocker` findings.
- **Why `can_proceed_to_next_stage` is `false` in `M1-002`**:
  - Read-only source preflight only inspects the **source** installation. Because destination environment compatibility, capacity, and collision checks are not yet verified (`target_expectations.verification_status = 'unverified'`, reported via `SC_TARGET_UNVERIFIED`), `PreflightReport` enforces `can_proceed_to_next_stage = false` so callers never mistake a source-only inspection for full migration readiness.

### 6.2 Machine-Readable Finding Identifiers

| Finding ID | Severity | Condition |
|---|---|---|
| `SC_AUTH_FORBIDDEN` | `blocker` | Current user lacks `manage_network` (multisite) or `manage_options` (standalone). |
| `SC_INVALID_MIGRATION_MODE` | `blocker` | Requested mode is not one of the four `MigrationMode` constants. |
| `SC_MODE_SOURCE_MISMATCH` | `blocker` | Multisite mode requested on standalone install, or standalone mode requested on multisite. |
| `SC_SOURCE_SITE_ID_REQUIRED` | `blocker` | `subsite_to_standalone` or `subsite_to_multisite` called without a positive `$source_site_id`. |
| `SC_SOURCE_SITE_NOT_FOUND` | `blocker` | Requested `$source_site_id` does not exist in `get_site()`. |
| `SC_CUSTOM_USER_TABLE_UNSUPPORTED` | `blocker` | `CUSTOM_USER_TABLE` or `CUSTOM_USER_META_TABLE` is defined. |
| `SC_MULTI_NETWORK_UNSUPPORTED` | `blocker` | Multisite installation contains more than one network (`get_networks( ['count' => true] ) > 1`). |
| `SC_MISSING_CORE_BLOG_TABLES` | `blocker` | One or more core `blog` tables for the target source site do not physically exist. |
| `SC_SOURCE_SITE_NON_STANDARD_STATUS` | `warning` | Target source subsite is marked `archived`, `spam`, `deleted`, or `mature`. |
| `SC_UNKNOWN_CUSTOM_TABLES_DETECTED` | `warning` | Non-core tables matching the target site prefix exist in the database. |
| `SC_LEGACY_MULTISITE_TABLES_DETECTED` | `warning` | Legacy MU table `{$base_prefix}blog_versions` exists physically in the database. |
| `SC_LEGACY_BLOGS_DIR_UPLOADS` | `warning` | Legacy `blogs.dir` upload path or `ms_files_rewriting` option is active. |
| `SC_OFFLOADED_MEDIA_INDICATORS` | `warning` | Active plugin slugs indicate potential external cloud media offloading. |
| `SC_MAIN_SITE_SHARED_PREFIX_ISOLATION` | `info` | Selected source subsite is the network main site (`is_main_site = true`) sharing `$wpdb->base_prefix`. |
| `SC_TARGET_UNVERIFIED` | `info` | Destination environment is unverified during read-only source preflight. |

---

## 7. Synthetic Preflight Report Example

```json
{
  "schema_version": "1.0.0",
  "mode": "subsite_to_standalone",
  "source_site_id": 2,
  "status": "source_inspected",
  "can_proceed_to_next_stage": false,
  "target_expectations": {
    "expected_type": "standalone",
    "verification_status": "unverified"
  },
  "counts": {
    "blocker": 0,
    "warning": 1,
    "info": 1
  },
  "findings": [
    {
      "id": "SC_UNKNOWN_CUSTOM_TABLES_DETECTED",
      "severity": "warning",
      "message": "Custom or plugin-created database tables matching the source site prefix were detected and require explicit policy handling.",
      "context": {
        "tables": [
          "wp_2_custom_plugin_items"
        ]
      }
    },
    {
      "id": "SC_TARGET_UNVERIFIED",
      "severity": "info",
      "message": "Destination environment has not been inspected or verified; read-only source preflight cannot authorize migration execution.",
      "context": {
        "expected_type": "standalone",
        "verification_status": "unverified"
      }
    }
  ],
  "source_inventory": {
    "environment": {
      "wp_version": "6.7.2",
      "php_version": "8.2.10",
      "is_multisite": true,
      "subdomain_install": false,
      "current_network_id": 1,
      "network_count": 1,
      "current_blog_id": 1,
      "main_site_id": 1,
      "base_prefix": "wp_",
      "custom_user_table": null,
      "custom_user_meta_table": null
    },
    "target_source_site": {
      "blog_id": 2,
      "exists": true,
      "network_id": 1,
      "domain": "example.org",
      "path": "/discovery-subsite/",
      "is_main_site": false,
      "blog_prefix": "wp_2_",
      "status": {
        "public": 1,
        "archived": 0,
        "mature": 0,
        "spam": 0,
        "deleted": 0
      }
    }
  }
}
```
