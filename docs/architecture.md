# SiteConvoy Architecture & Subsystem Specification

> [!NOTE]
> **Status: PROPOSED / UNVALIDATED DESIGN**  
> **Task Reference:** `SITECONVOY-M0-002`  
> The architectural models and technical designs described in this document represent the planned design for SiteConvoy version 1.0. Sourced from primary WordPress core documentation and source codebase research. All items are design goals subject to empirical validation in future milestones.

---

## 1. High-Level Architecture Overview

SiteConvoy is structured around a pipeline of modular, decoupled subsystems:

```
+-----------------------------------------------------------------------------------+
|                                 SiteConvoy Engine                                 |
+-----------------------------------------------------------------------------------+
|  1. Preflight & Discovery  --> Scan environment, disk space, limits, collisions   |
|  2. Backup & Checkpointing --> Establish restore points, durable disk journal     |
|  3. Archive Packaging      --> Chunked extraction, streaming compression, SHA256  |
|  4. Transform Engine       --> Table prefix rewrite, user review, serialization   |
|  5. Asset & Theme Provision--> Themes, plugins, mu-plugins, drop-ins, translations|
|  6. Filesystem Manager     --> Media translocation, containment & atomic rename   |
|  7. Recovery & Rollback    --> Best-effort compensation, journaled manual recovery|
+-----------------------------------------------------------------------------------+
```

Detailed research backing these subsystems:
- [WordPress Core Data Inventory](research/core-data-inventory.md)
- [Migration Feasibility Analysis](research/migration-feasibility.md)
- [Security Threat Model](research/security-threat-model.md)
- [Testing Strategy & Fixtures](test-strategy.md)
- [Authoritative Sources Bibliography](research/sources.md)

---

## 2. Migration State Machine & Transition Boundaries

SiteConvoy executes migrations according to a deterministic state machine, ensuring idempotency, resumability, and clear boundaries for rollback:

```
[INITIATED]
    |
    v
[PREFLIGHT_RUNNING] ---- (Preflight Fails) ----> [PREFLIGHT_FAILED] (Halt; zero mutations)
    |
    v
[PREFLIGHT_PASSED]
    |
    v
[BACKUP_CREATING]   ---- (Backup Fails) -------> [ABORTED] (Halt; zero mutations)
    |
    v
[PACKAGING] / [FETCHING]
    |
    v
[VERIFYING_CHECKSUM] - (Checksum Mismatch) ----> [REJECTED] (Halt; discard archive)
    |
    v
[TRUST_APPROVAL]    ---- (Unapproved Trust) ---> [PENDING_OPERATOR_APPROVAL]
    |
    v
[STAGING_FILES]     ---- (Extract/Disk Error) -> [CLEANUP_STAGING] (Purge tmp/)
    |
    v
[PROVISIONING_ASSETS] -- (Missing Dep / Incompat) -> [BLOCKED_DEPENDENCY]
    |
    v
[TRANSFORMING_DB]   ---- (SQL Failure) --------> [COMPENSATING_ROLLBACK] (Drop staged tables)
    |
    v
[COMMITTING_FILES]  ---- (FS Swap Error) ------> [MANUAL_RECOVERY_REQUIRED]
    |
    v
[POST_VERIFICATION]
    |
    v
[COMPLETED]
```

### Destination Mutation Boundary
- **Zero-Mutation Stages**: `INITIATED`, `PREFLIGHT_RUNNING`, `PREFLIGHT_PASSED`, `BACKUP_CREATING`, `PACKAGING`, `VERIFYING_CHECKSUM`, `TRUST_APPROVAL`, `STAGING_FILES`, `PROVISIONING_ASSETS`. At these stages, target live tables and live content directories remain completely untouched.
- **Controlled-Mutation Stages**: `TRANSFORMING_DB` (operates on staged tables or restore points), `COMMITTING_FILES` (swaps files from `tmp/`).

---

## 3. Package Structure and Manifest Concept

SiteConvoy migration packages are standard compressed tar/zip archives (`.convoy` / `.tar.gz`) containing a structured manifest and isolated assets:

```
archive-siteconvoy-20261101-123456.tar.gz
├── manifest.json
├── checksums.sha256
├── database/
│   ├── schema.sql
│   ├── tables/
│   │   ├── posts.sql
│   │   ├── postmeta.sql
│   │   └── options.sql
│   └── users.sql
├── uploads/
│   └── [media files tree...]
└── assets/
    ├── plugins/
    ├── themes/
    ├── mu-plugins/
    ├── drop-ins/
    └── languages/
```

### Manifest Schema (`manifest.json`)
```json
{
  "format_version": "1.0",
  "generated_at": 1793498800,
  "plugin_version": "1.0.0-dev",
  "source": {
    "site_url": "https://network.local/site2",
    "home_url": "https://network.local/site2",
    "multisite": true,
    "blog_id": 2,
    "base_prefix": "wp_",
    "blog_prefix": "wp_2_",
    "wp_version": "6.7",
    "php_version": "8.2.10",
    "charset": "utf8mb4",
    "collate": "utf8mb4_unicode_520_ci"
  },
  "mode": "subsite_to_standalone",
  "tables": ["wp_2_posts", "wp_2_postmeta", "wp_2_options", "wp_2_comments"],
  "user_count": 3,
  "media_summary": {
    "file_count": 45,
    "total_bytes": 15420310
  },
  "assets_manifest": {
    "themes": [
      { "slug": "twentytwentyfour", "version": "1.2", "parent": null }
    ],
    "plugins": [
      { "slug": "woocommerce", "file": "woocommerce/woocommerce.php", "version": "9.3.0", "network_active": false }
    ],
    "mu_plugins": [],
    "drop_ins": [],
    "languages": ["en_US"]
  }
}
```

---

## 4. Key Architectural Subsystems & Risk Mitigations

### 4.1 Database Table Isolation & Dynamic Prefix Discovery
- Naive tools assume hardcoded `wp_` or `wp_{id}_` prefixes.
- SiteConvoy queries [`wpdb::tables()`](https://developer.wordpress.org/reference/classes/wpdb/tables/) and [`wpdb::get_blog_prefix( $blog_id )`](https://developer.wordpress.org/reference/classes/wpdb/get_blog_prefix/) to derive exact table names. Calling `$wpdb->tables('global')` on multisite returns both shared users and `ms_global` governance tables, while `$scope = 'ms_global'` provides an isolated catalog.
- Tables are transformed with strict boundaries to avoid accidental replacement in column data.

### 4.2 Main Site (Site ID 1) Asymmetry
- Site ID 1 subsite tables share names with global network tables (`wp_posts`, `wp_options` alongside `wp_users`, `wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`, `wp_blog_versions`).
- Naive export captures network state. SiteConvoy enforces explicit inclusion lists separating subsite tables from `ms_global` tables and purges network-only keys (`active_sitewide_plugins`) from options.

### 4.3 Asset Discovery, Provisioning, and Dependency Management
In full-network migrations (and subsite moves where custom code is required), SiteConvoy implements rigorous asset discovery:
1. **Themes & Child Themes**:
   - Inspects both active themes on individual subsites and network-enabled themes in `wp_sitemeta` (`allowedthemes`).
   - Detects parent-child relationships; if a child theme is present, the parent theme directory must also be discovered and transferred.
2. **Plugins & Network Activation**:
   - Inspects `active_sitewide_plugins` in `wp_sitemeta` and active plugins in `wp_{id}_options`.
   - Distinguishes network-activated plugins (which must retain network activation on destination) from per-site activations.
3. **Must-Use Plugins (`mu-plugins`) & Drop-Ins**:
   - Scans `wp-content/mu-plugins/` and core drop-in files (`db.php`, `object-cache.php`, `advanced-cache.php`, `sunrise.php`).
   - *Safety Rule*: Drop-ins that bind directly to host infrastructure (e.g. host-specific Redis/Memcached drop-ins or custom database drivers) are flagged during preflight. If incompatible with the destination environment, migration is **BLOCKED / WARNED** rather than silently deploying broken execution hooks.
4. **Translations & Custom Content**:
   - Scans `wp-content/languages/` and custom directories defined by `WP_CONTENT_DIR` or plugin constants.
5. **Dependency Validation**:
   - If required themes or plugins cannot be transferred (e.g. due to filesystem permission constraints or archive omission), the importer validates whether matching dependencies exist on the target. If absent, the migration enters `BLOCKED_DEPENDENCY` state and halts before mutating destination content.

### 4.4 Identity Resolution & User Protection
- Source multisite users are shared. SiteConvoy extracts only users with explicit roles or authorship ties on the site.
- **Security Rule**: Silent account linking and automatic privilege escalation are prohibited. Collision review is required by a trusted network admin. Super-admin rights are never imported.

### 4.5 Data Search-and-Replace & GUID Preservation
- Serialized PHP objects and JSON strings are recursively unserialized, transformed, and reserialized with character-length verification.
- In accordance with WordPress core migration standards and WP-CLI (`--skip-columns=guid`), `wp_posts.guid` entries are immutable identifiers and **must not be rewritten**.

### 4.6 Media Filesystem Management & Containment
- Media paths are mapped cleanly between `wp-content/uploads/sites/{id}/` (multisite) and `wp-content/uploads/` (standalone), synchronizing attachment metadata without altering existing GUIDs.
- Filesystem extraction validates entry normalization and enforces directory containment inside `tmp/`, rejecting symlinks and hardlinks pointing outside the sandbox.

### 4.7 Tested Reliability & Recovery Goals
- Universal atomic rollback is impossible across non-transactional MySQL operations (DDL) and disparate filesystems.
- Reliability is achieved through:
  - Preflight dry-run validation.
  - Backup restore points.
  - Isolated staging directories (`tmp/`).
  - Durable pre-batch checkpoints persisted to disk prior to hazardous executions (mitigating uncatchable PHP fatal OOM).
  - Clear manual recovery documentation and state logs.
