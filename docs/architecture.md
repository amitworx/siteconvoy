# SiteConvoy Architecture & Subsystem Specification

> [!NOTE]
> **Status: PROPOSED / UNVALIDATED DESIGN**  
> **Task Reference:** `SITECONVOY-M0-002`  
> The architectural models and technical designs described in this document represent the planned design for SiteConvoy version 1.0. Sourced from empirical core research and primary WordPress documentation. All items are design goals subject to empirical validation in future milestones.

---

## 1. High-Level Architecture Overview

SiteConvoy is structured around a pipeline of modular, decoupled subsystems:

```
+-----------------------------------------------------------------------------------+
|                                 SiteConvoy Engine                                 |
+-----------------------------------------------------------------------------------+
|  1. Preflight & Discovery  --> Scan environment, disk space, limits, collisions   |
|  2. Backup & Checkpointing --> Establish restore points, journal stage state      |
|  3. Archive Packaging      --> Chunked extraction, streaming compression, SHA256  |
|  4. Transform Engine       --> Table prefix rewrite, user review, serialization   |
|  5. Filesystem Manager     --> Media translocation, staged copy & atomic rename   |
|  6. Recovery & Rollback    --> Best-effort compensation, journaled manual recovery|
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
[STAGING_FILES]     ---- (Extract/Disk Error) -> [CLEANUP_STAGING] (Purge tmp/)
    |
    v
[TRANSFORMING_DB]   ---- (SQL Failure) --------> [COMPENSATING_ROLLBACK] (Restore DB)
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
- **Zero-Mutation Stages**: `INITIATED`, `PREFLIGHT_RUNNING`, `PREFLIGHT_PASSED`, `BACKUP_CREATING`, `PACKAGING`, `VERIFYING_CHECKSUM`, `STAGING_FILES`. At these stages, target live tables and media directories are completely untouched.
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
└── uploads/
    └── [media files tree...]
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
  }
}
```

---

## 4. Key Architectural Subsystems & Risk Mitigations

### 4.1 Database Table Isolation & Dynamic Prefix Discovery
- Naive tools assume hardcoded `wp_` or `wp_{id}_` prefixes.
- SiteConvoy queries [`wpdb::tables()`](https://developer.wordpress.org/reference/classes/wpdb/tables/) and [`wpdb::get_blog_prefix( $blog_id )`](https://developer.wordpress.org/reference/classes/wpdb/get_blog_prefix/) to derive exact table names.
- Tables are transformed with strict boundaries to avoid accidental replacement in column data.

### 4.2 Main Site (Site ID 1) Asymmetry
- Site ID 1 subsite tables share names with global network tables (`wp_posts`, `wp_options` alongside `wp_users`, `wp_blogs`, `wp_site`, `wp_sitemeta`).
- Naive export captures network state. SiteConvoy enforces explicit inclusion lists separating subsite tables from `ms_global` tables and purges network-only keys (`active_sitewide_plugins`) from options.

### 4.3 Identity Resolution & User Protection
- Source multisite users are shared. SiteConvoy extracts only users with explicit roles or authorship ties on the site.
- **Security Rule**: Silent account linking and automatic privilege escalation are prohibited. Collision review is required by a trusted network admin. Super-admin rights are never imported.

### 4.4 Data Search-and-Replace & GUID Preservation
- Serialized PHP objects and JSON strings are recursively unserialized, transformed, and reserialized with character-length verification.
- In accordance with WordPress core migration standards and WP-CLI (`--skip-columns=guid`), `wp_posts.guid` entries are immutable identifiers and **must not be rewritten**.

### 4.5 Media Filesystem Management
- Media paths are mapped cleanly between `wp-content/uploads/sites/{id}/` (multisite) and `wp-content/uploads/` (standalone), synchronizing attachment metadata without altering existing GUIDs.

### 4.6 Extension Adapters & Third-Party Table Hooks
- Extensible adapter architecture allowing third-party plugins (e.g. WooCommerce, SEO plugins) to hook into preflight discovery, table classification, and URL replacement pipelines without polluting core engine code.

### 4.7 Tested Reliability & Recovery Goals
- Universal atomic rollback is impossible across non-transactional MySQL operations (DDL) and disparate filesystems.
- Reliability is achieved through:
  - Preflight dry-run validation.
  - Backup restore points.
  - Isolated staging directories (`tmp/`).
  - Persistent state journaling for idempotency and resumption.
  - Clear manual recovery documentation and state logs.
