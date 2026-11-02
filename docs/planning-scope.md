# Migration Scope Planner & Table Selection Contracts

> [!NOTE]
> **Task Reference:** `SITECONVOY-M1-003`  
> **Status:** IMPLEMENTED (`0.1.0-dev`) &mdash; READ-ONLY PREVIEW PLANNING ONLY  
> This document defines the PHP contracts, deterministic table selection rules, capability guards, metadata dependency resolution, and non-executable plan invariants introduced in `SITECONVOY-M1-003`. These components are strictly **read-only**: they do not export packages, import data, write options, modify database schemas, or expose admin/REST endpoints.

---

## 1. Subsystem Overview & Architecture

The `SITECONVOY-M1-003` planning subsystem extends the read-only discovery foundation (`SITECONVOY-M1-002`) by turning source discovery inventories and preflight evaluations into a deterministic, structured, and auditable preview plan:

| Class | File | Responsibility |
|---|---|---|
| `SiteConvoy\Contracts\MigrationPlan` | `includes/Contracts/MigrationPlan.php` | Immutable value object representing a planned migration preview. Implements `\JsonSerializable`. Guarantees `is_executable() === false` invariant. |
| `SiteConvoy\Planning\MigrationScopePlanner` | `includes/Planning/MigrationScopePlanner.php` | Deterministic scope planner validating authorization via `PreflightService`, classifying tables across 4 modes, discovering metadata dependencies, and building the `MigrationPlan`. |

```mermaid
flowchart TD
    Caller["Caller / Future WP-CLI / REST (Milestone M2+)"] -->|generate_plan| Planner["MigrationScopePlanner"]
    Planner -->|PreflightService::run| Preflight["PreflightService (Capability & Topology Gate)"]
    Preflight -->|deny| BlockedPlan["MigrationPlan (status: blocked / unauthorized, is_executable: false)"]
    Preflight -->|pass| Discovery["SourceDiscoveryService (Read-Only DB / WP-Context)"]
    Discovery --> Planner
    Planner --> Classify["4-Mode Deterministic Table Classifier"]
    Planner --> Deps["Metadata Dependency Discovery (active plugins/theme/drop-ins)"]
    Planner --> Checklists["Outstanding Verification Checklist Generator"]
    Classify & Deps & Checklists --> Plan["MigrationPlan (1.0.0, is_executable: false)"]
```

---

## 2. Invariants & Security Boundaries

1. **Deterministic & Read-Only**:
   - `MigrationScopePlanner::generate_plan()` produces bit-identical plans across repeated runs on unchanged source databases.
   - It executes no write queries, creates no database locks, provisions no files, and modifies no options or transients.
2. **Fail-Closed Execution Guard (`is_executable === false`)**:
   - `MigrationPlan::is_executable()` unconditionally returns `false`.
   - The serialized JSON plan explicitly contains `"is_executable": false`.
   - Any plan with blocking preflight findings or missing prerequisites is created with `status: "blocked"` or `status: "unauthorized"`.
3. **Authorization & Defense-in-Depth**:
   - `MigrationScopePlanner::generate_plan()` invokes `PreflightService::run()`, requiring `manage_network` on multisite installations and `manage_options` on standalone installations.
   - Unauthorized attempts return a plan with `status: "unauthorized"`, an empty table manifest, and a blocking preflight finding `SC_AUTH_FORBIDDEN`.
4. **Current-Network Ownership & Isolation**:
   - `SourceDiscoveryService::enumerate_network_sites()` strictly validates any provided `$network_id`. Querying a foreign network ID throws `\InvalidArgumentException`.
   - In `MigrationScopePlanner`, site existence and current-network ownership are strictly checked against `get_site( $site_id )` and `(int) $site->site_id === (int) $wpdb->siteid`.
5. **Data Minimization & Zero Sensitive Data**:
   - Plans omit database passwords, salts, keys, user emails, password hashes, and user login names.
   - Dependency discovery reads only metadata (plugin names, version headers, theme text domain, and active option keys).

---

## 3. Four-Direction Table Selection Rules

WordPress core tables are categorized into four logical scopes:
- `blog`: Site-specific content tables (`posts`, `postmeta`, `comments`, `commentmeta`, `terms`, `termmeta`, `term_taxonomy`, `term_relationships`, `options`, `links`).
- `global`: User identity tables (`users`, `usermeta`).
- `ms_global`: Multisite network governance tables (`blogs`, `blogmeta`, `site`, `sitemeta`, `signups`, `registration_log`).
- `unresolved`: Custom or unclassified plugin tables present in the database.

Each table entry in the manifest receives a status:
- `included`: Selected as part of the extraction scope.
- `excluded`: Explicitly rejected from extraction with an audit reason.
- `needs_review`: Flagged for user or operator decision (e.g. shared user migration).
- `missing`: Expected core table absent from the database.
- `unresolved`: Custom table requiring administrator classification.

### Mode 1: Subsite &rarr; Standalone (`subsite_to_standalone`)
- **Blog tables**: All core blog tables for the subsite are marked `status: "included"`.
- **Main Site (Site ID 1) Isolation**: Site 1 blog tables share `$wpdb->base_prefix` (`wp_`) with `ms_global` tables. The planner specifically identifies `ms_global` tables and marks them `status: "excluded"` with reason: `Multisite network governance tables are excluded from single-subsite export.`.
- **User identity (`wp_users`, `wp_usermeta`)**: Categorized under `global` with `status: "excluded"` and reason: `Shared network user tables are excluded from automated subsite transfer; individual user accounts and roles require explicit selective mapping review.`.
- **Unresolved / Custom tables**: Prefixed tables matching the blog prefix are categorized under `unresolved` with `status: "unresolved"`.

### Mode 2: Subsite &rarr; Multisite (`subsite_to_multisite`)
- **Blog tables**: All core blog tables for the target subsite are marked `status: "included"`.
- **Multisite Global tables**: Marked `status: "excluded"`.
- **User identity (`wp_users`, `wp_usermeta`)**: Marked `status: "excluded"` under `global` for downstream selective identity mapping and collision detection on the destination network.
- **Unresolved tables**: Marked `status: "unresolved"`.

### Mode 3: Whole Multisite Network &rarr; New Environment (`full_network_to_new_env`)
- **Site ID validation**: `$source_site_id` must be `null`.
- **Multi-network guard**: If more than 1 network exists in `wp_site`, planning is blocked (`SC_MULTI_NETWORK_UNSUPPORTED`).
- **All Network Sites' Blog tables**: Enumerates all active sites via `get_sites()` and includes core blog tables for every site across the network.
- **Physical Existence Verification & Fail-Closed Scoping**: The planner verifies the physical existence of expected core blog tables for all enumerated network sites as well as core `ms_global` tables against the physical table inventory (`SHOW TABLES`). Any missing expected core table is marked with `status: "missing"`. If any core table is missing, the planner transitions to a fail-closed blocked state (`status: "blocked"`, preflight blocker `SC_MISSING_CORE_TABLES_PLAN`, `is_executable: false`).
- **Deprecated / Legacy Core Tables (`core_old`)**: Deprecated core tables present in the physical database (e.g., `sitecategories`, `categories`) are surfaced under `unresolved` with `status: "needs_review"` and an audit reason identifying them as legacy WordPress core tables for operator evaluation.
- **Multisite Global tables**: Marked `status: "included"` under `ms_global` as candidates for network governance transfer when physically present.
- **User identity (`wp_users`, `wp_usermeta`)**: Marked `status: "needs_review"` under `global` with reason: `Network-wide user identity and authentication credentials require verification and password/cookie salt review at target.`.
- **Unresolved tables**: Marked `status: "unresolved"`.

### Mode 4: Standalone &rarr; Multisite Subsite (`standalone_to_subsite`)
- **Source environment**: Must be standalone (`is_multisite() === false`).
- **Blog tables**: All standalone blog tables are marked `status: "included"` under `blog` for downstream prefix rewriting and insertion.
- **Multisite Global tables**: None exist on source (`ms_global` category is empty array `[]`).
- **User identity (`wp_users`, `wp_usermeta`)**: Marked `status: "needs_review"` under `global` with reason: `Standalone user accounts require review for email/username collisions against destination multisite users before mapping.`.
- **Unresolved tables**: Marked `status: "unresolved"`.

---

## 4. Metadata-Only Dependency Extraction

The `dependencies` section inspects active runtime components without serializing code or executing side effects:
- **Active Plugins**: Discovered shallow metadata relative paths (`slug`), network-activation flags (`sitewide`), and review state (`status: "review_required"`).
- **Active Themes**: Current active theme slug (`stylesheet`), template slug, and parent indicator if child theme.
- **Drop-Ins & MU-Plugins**: Discovered drop-in filenames (`advanced-cache.php`, `object-cache.php`, `db.php`, `sunrise.php`) and must-use plugin filenames from discovery inventory.
- **Media Uploads Layout**: Filesystem directory layout flags (`legacy_blogs_dir` vs `standard`), `ms_files_rewriting`, and detected offload indicators.
- **Per-Site Dependencies (Full Network)**: In `full_network_to_new_env` mode, per-site theme configurations (`stylesheet`, `template`, `is_child`) and active plugin relative slugs are captured across all network sites under the `per_site` key, using clean context switching (`switch_to_blog()` wrapped in `try ... finally { restore_current_blog(); }`).

---

## 5. Outstanding Verifications

The plan generates an outstanding verification checklist for operators and automated preflight validators before any future migration stage can proceed in M2+:

1. `destination_environment_connectivity_and_compatibility`: Destination environment connectivity and version compatibility.
2. `user_identity_collision_and_role_mapping_approval`: Verification of administrator and author user accounts, email collisions, and role mappings.
3. `unresolved_custom_and_plugin_table_disposition`: Explicit operator review and disposition policy for custom/unknown database tables.
4. `pre_migration_backup_checkpoint_creation`: Creation and verification of restorable pre-migration backup snapshots.
5. `operator_explicit_execution_approval`: Explicit privileged administrator approval before cutover execution.

---

## 6. MigrationPlan JSON Schema (v1.0.0)

The canonical JSON Schema is located in [`schemas/migration-plan.json`](../schemas/migration-plan.json).

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "SiteConvoyMigrationPlan",
  "type": "object",
  "required": [
    "schema_version",
    "mode",
    "source_site_id",
    "status",
    "is_executable",
    "source_topology",
    "tables",
    "dependencies",
    "outstanding_verifications",
    "preflight"
  ],
  "properties": {
    "schema_version": {
      "type": "string",
      "const": "1.0.0"
    },
    "mode": {
      "type": "string",
      "enum": [
        "subsite_to_standalone",
        "subsite_to_multisite",
        "full_network_to_new_env",
        "standalone_to_subsite"
      ]
    },
    "source_site_id": {
      "type": [
        "integer",
        "null"
      ]
    },
    "status": {
      "type": "string",
      "enum": [
        "draft",
        "needs_review",
        "blocked",
        "unauthorized"
      ]
    },
    "is_executable": {
      "type": "boolean",
      "const": false
    },
    "source_topology": {
      "type": "object",
      "required": [
        "is_multisite",
        "current_network_id",
        "base_prefix",
        "site_count"
      ]
    },
    "tables": {
      "type": "object",
      "required": [
        "blog",
        "global",
        "ms_global",
        "unresolved"
      ]
    },
    "dependencies": {
      "type": "object",
      "required": [
        "themes",
        "plugins",
        "mu_plugins",
        "dropins",
        "media"
      ]
    },
    "outstanding_verifications": {
      "type": "array",
      "items": {
        "type": "string"
      }
    },
    "preflight": {
      "type": [
        "object",
        "null"
      ]
    }
  }
}
```
