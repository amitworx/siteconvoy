# SiteConvoy Architecture & Risk Analysis

> [!NOTE]
> **Status: PROPOSED / UNVALIDATED DESIGN**  
> The architectural models and technical designs described in this document represent the planned design for SiteConvoy version 1.0. They have not yet been validated by executable implementations or integration tests. All items are subject to refinement during upcoming development milestones.

---

## High-Level Architecture Overview

SiteConvoy is structured around a pipeline of modular, isolated subsystems:

```
+-----------------------------------------------------------------------------------+
|                                 SiteConvoy Engine                                 |
+-----------------------------------------------------------------------------------+
|  1. Preflight & Discovery  --> Scan environment, disk space, limits, collision   |
|  2. Archive Packaging      --> Chunked extraction, streaming compression, SHA256  |
|  3. Transform Engine       --> Table prefix rewrite, user remapping, serialization|
|  4. Filesystem Manager     --> Media translocation, atomic stage-and-swap         |
|  5. Verification & Rollback--> Cryptographic checksum, integrity test, undo log  |
+-----------------------------------------------------------------------------------+
```

---

## Architectural Subsystems and Risk Factors

### 1. Database Table Isolation & Prefix Rewriting
- **Design**:
  - In WordPress Multisite, the main site (Site ID 1) typically shares the root database prefix (e.g., `wp_posts`, `wp_options`), while secondary subsites use numbered prefixes (e.g., `wp_2_posts`, `wp_2_options`).
  - When extracting a subsite to standalone, `wp_{id}_*` tables must be transformed to `wp_*`.
  - When importing standalone to subsite, `wp_*` tables must be converted to `wp_{new_id}_*`.
- **Risks & Mitigation**:
  - *Risk*: Blind regex replacements on table prefixes can corrupt custom tables or column names containing similar strings.
  - *Mitigation*: Tables must be discovered through dynamic schema inspection (`SHOW TABLES LIKE...`), isolated individually, and renamed with strict boundaries.

### 2. Main Site (Site ID 1) Extraction
- **Design**:
  - Site ID 1 is unique: its options and post tables share names with global network tables or use non-numbered prefixes alongside global tables (`wp_users`, `wp_usermeta`, `wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`).
- **Risks & Mitigation**:
  - *Risk*: Attempting to extract Site ID 1 by taking all `wp_*` tables will inadvertently export global multisite metadata, polluting the standalone target with irrelevant network state.
  - *Mitigation*: Explicit table inclusion lists separating subsite tables from global network tables. Option filtering to drop network-specific option keys (e.g., `active_sitewide_plugins`, `site_admins`).

### 3. Shared Users and Role Remapping
- **Design**:
  - In multisite, `wp_users` is shared across all sites. User capabilities and roles are stored in `wp_usermeta` under site-specific meta keys (`wp_capabilities` for site 1, `wp_{id}_capabilities` for subsite `id`).
- **Risks & Mitigation**:
  - *Risk (Subsite to Standalone)*: Copying all network users leaks accounts that have no relationship to the subsite.
  - *Mitigation*: Only users with explicit roles or authorship records on the migrated site will be extracted. Usermeta keys `wp_{id}_capabilities` and `wp_{id}_user_level` must be rewritten to `wp_capabilities` and `wp_user_level`.
  - *Risk (Subsite to Target Multisite)*: ID collisions where User ID 5 on the source site represents a different person than User ID 5 on the target network.
  - *Mitigation*: User resolution strategy: Match by verified `user_email` or `user_login` if identical; otherwise generate a new user record and remap `post_author` and associated IDs throughout all migrated site tables.

### 4. Serialized Data and Deep Search-and-Replace
- **Design**:
  - WordPress options, postmeta, and widget configurations store serialized PHP objects and JSON strings containing site URLs and directory paths.
- **Risks & Mitigation**:
  - *Risk*: Direct SQL `REPLACE()` breaks PHP serialized string length counts (e.g., `s:19:"http://old-site.com"` becoming invalid when changed to `http://new-domain.org`), resulting in broken settings and corrupted widgets.
  - *Mitigation*: Use a robust recursive unserialize/replace/reserialize engine that correctly handles multi-byte UTF-8 characters and nested structures.

### 5. Media Paths and Filesystem Differences
- **Design**:
  - Standalone upload path: `wp-content/uploads/YYYY/MM/...`
  - Multisite subsite upload path: `wp-content/uploads/sites/{id}/YYYY/MM/...` (or legacy `blogs.dir/{id}/files/`).
- **Risks & Mitigation**:
  - *Risk*: Broken media links in post content and broken database references in `_wp_attached_file`.
  - *Mitigation*: File translocation must synchronize with database URL replacements and `_wp_attached_file` postmeta adjustments.

### 6. Network-Activated Plugins and Custom Tables
- **Design**:
  - Network-activated plugins may store data in global tables, subsite tables, or custom unindexed tables.
- **Risks & Mitigation**:
  - *Risk*: Missing plugin tables or broken dependencies when moving a subsite to an environment where the plugin is not installed or network-activated.
  - *Mitigation*: Preflight inspection cataloging active plugins and custom database tables, alerting administrators to required target plugins.

### 7. Large Archives, Execution Timeouts, and Resumability
- **Design**:
  - Large multisite networks can span tens of gigabytes of media and massive database tables.
- **Risks & Mitigation**:
  - *Risk*: PHP `max_execution_time` and `memory_limit` exhaustion causing partial exports or incomplete imports.
  - *Mitigation*: Batch-oriented, chunked processing with persistent execution state and streaming file archives, allowing resumable operations.

### 8. Preflight Checks, Dry-Run Validation, and Rollback
- **Design**:
  - Every migration must pass an automated preflight dry-run check:
    - Sufficient disk space for temporary staging and extracted files.
    - Database user privileges (`CREATE`, `DROP`, `ALTER`, `INDEX`).
    - Detection of existing table collisions on target.
    - PHP memory and timeout constraints.
  - Safe rollback: Any failure during extraction or database restoration must revert changes to ensure zero data loss.

### 9. Authentication and Permission Enforcement
- **Design**:
  - Migration actions are high-privilege operations.
- **Risks & Mitigation**:
  - *Risk*: Unauthorized remote access or CSRF vulnerabilities during archive generation or import.
  - *Mitigation*: Restrict operations strictly to users with `manage_network` privileges (or `manage_options` on standalone), verified via nonces, cryptographic HMAC tokens for multi-step tasks, and rate limiting.
