# SiteConvoy Architecture & Risk Analysis

> [!NOTE]
> **Status: PROPOSED / UNVALIDATED DESIGN**  
> The architectural models and technical designs described in this document represent the planned design for SiteConvoy version 1.0. They have not yet been validated by executable implementations or integration tests. All items are design goals subject to empirical refinement and testing during upcoming development milestones.

---

## High-Level Architecture Overview

SiteConvoy is structured around a pipeline of modular, isolated subsystems:

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

---

## Architectural Subsystems and Risk Factors

### 1. Database Table Isolation & Prefix Rewriting
- **Design**:
  - In WordPress Multisite, the main site (Site ID 1) typically shares the root database prefix (e.g., `wp_posts`, `wp_options`), while secondary subsites use numbered prefixes (e.g., `wp_2_posts`, `wp_2_options`).
  - When extracting a subsite to standalone, `wp_{id}_*` tables must be transformed to `wp_*`.
  - When importing standalone to subsite, `wp_*` tables must be converted to `wp_{new_id}_*`.
- **Risks & Mitigation**:
  - *Risk*: Blind regex replacements on table prefixes can corrupt custom tables or column names containing similar strings.
  - *Mitigation*: Tables must be discovered through dynamic schema inspection (`SHOW TABLES LIKE...`), isolated individually, and renamed with strict boundary delimiters.

### 2. Main Site (Site ID 1) Extraction
- **Design**:
  - Site ID 1 is unique: its options and post tables share names with global network tables or use non-numbered prefixes alongside global tables (`wp_users`, `wp_usermeta`, `wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`).
- **Risks & Mitigation**:
  - *Risk*: Attempting to extract Site ID 1 by taking all `wp_*` tables will inadvertently export global multisite metadata, polluting the standalone target with irrelevant network state.
  - *Mitigation*: Explicit table inclusion lists separating subsite tables from global network tables. Option filtering to drop network-specific option keys (e.g., `active_sitewide_plugins`, `site_admins`).

### 3. Shared Users, Identity Resolution & Permission Safety
- **Design**:
  - In multisite, `wp_users` is shared across all sites. User capabilities and roles are stored in `wp_usermeta` under site-specific meta keys (`wp_capabilities` for site 1, `wp_{id}_capabilities` for subsite `id`).
- **Risks & Mitigation**:
  - *Risk (Subsite to Standalone User Leakage)*: Copying all network users leaks accounts that have no relationship to the subsite.
    - *Mitigation*: Only users with explicit roles or authorship records on the migrated site will be extracted. Usermeta keys `wp_{id}_capabilities` and `wp_{id}_user_level` must be rewritten to `wp_capabilities` and `wp_user_level`.
  - *Risk (Insecure Account Linking & Privilege Escalation)*: Inferring account identity from matching emails or logins alone is dangerous; an attacker could craft an export containing an administrator's email to hijack an account on the target network, or an import could silently grant unintended privileges.
    - *Mitigation*:
      - **No silent linking**: Never automatically merge or associate users based on email or username match alone.
      - **No imported super-admins**: Never grant network-level super-administrator (`manage_network`) capabilities via imported archives.
      - **Explicit admin approval**: Flag all email/login collisions in preflight review and require explicit confirmation by a trusted administrator on the target system.
      - **Account creation & remapping**: When accounts are distinct, generate new non-colliding accounts and remap all `post_author`, revision, and meta references.
      - **Unresolved edge cases**: Maintain open test requirements for handling orphaned author references, custom user taxonomy terms, and multi-network cross-table user capabilities.

### 4. Serialized Data and Deep Search-and-Replace
- **Design**:
  - WordPress options, postmeta, and widget configurations store serialized PHP objects and JSON strings containing site URLs and directory paths.
- **Risks & Mitigation**:
  - *Risk*: Direct SQL `REPLACE()` breaks PHP serialized string length counts (e.g., `s:19:"http://old-site.com"` becoming invalid when changed to `http://new-domain.org`), resulting in broken settings and corrupted widgets.
  - *Mitigation*: Use a robust recursive unserialize/replace/reserialize engine that correctly handles multi-byte UTF-8 characters and nested structures.
  - *Risk*: Modifying GUID values in `wp_posts.guid` breaks external feed readers and syndication identifiers.
  - *Mitigation*: Explicitly skip `wp_posts.guid` during search-and-replace, following WordPress core and WP-CLI standards (`--skip-columns=guid`).

### 5. Media Paths and Filesystem Differences
- **Design**:
  - Standalone upload path: `wp-content/uploads/YYYY/MM/...`
  - Multisite subsite upload path: `wp-content/uploads/sites/{id}/YYYY/MM/...` (or legacy `blogs.dir/{id}/files/`).
- **Risks & Mitigation**:
  - *Risk*: Broken media links in post content and broken database references in `_wp_attached_file`.
  - *Mitigation*: File translocation must synchronize with database URL replacements and `_wp_attached_file` postmeta adjustments while leaving `wp_posts.guid` unchanged.

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
  - *Mitigation*: Batch-oriented, chunked processing with persistent execution journaling and streaming file archives, allowing resumable operations across multiple HTTP requests or CLI executions.

### 8. Reliability, Rollback, and Recovery Design Goals
- **Design**:
  - Achieving universal, atomic "all-or-nothing" rollback across disparate filesystems, database storage engines (e.g. MyISAM or non-transactional DDL operations like `CREATE TABLE` and `DROP TABLE` in MySQL), and remote network streams is physically impossible without disciplined staging and recovery mechanisms.
  - Reliability is treated as a set of rigorous **design goals to test**:
    - **Pre-flight Dry-Run**: Validates disk space, execution limits, database user privileges (`CREATE`, `DROP`, `ALTER`, `INDEX`), and table collisions before executing mutations.
    - **Backup Restore Points**: Snapshot existing site tables and configuration before beginning target writes.
    - **Staged Staging Directory**: Perform file extraction and decompression in an isolated temporary staging directory (`tmp/`) prior to atomic filesystem move/swap.
    - **Import Journaling & Checkpoints**: Track migration progress step-by-step in a state journal, enabling idempotency and resumption.
    - **Best-Effort Compensating Rollback**: In case of runtime failure, execute clean-up compensations where safe (e.g., removing newly created staged tables and directories).
    - **Clear Manual Recovery States**: When partial failures cannot be safely reverted automatically (e.g., mid-DDL interruption), present detailed diagnostic reports and logs allowing manual recovery from restore points.

### 9. Authentication and Permission Enforcement
- **Design**:
  - Migration actions are high-privilege operations.
- **Risks & Mitigation**:
  - *Risk*: Unauthorized remote access or CSRF vulnerabilities during archive generation or import.
  - *Mitigation*: Restrict operations strictly to users with `manage_network` privileges (or `manage_options` on standalone), verified via nonces, cryptographic HMAC tokens for multi-step tasks, and rate limiting.
