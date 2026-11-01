# SiteConvoy Migration Matrix & Acceptance Criteria

> [!NOTE]
> **Status: PROPOSED SPECIFICATION**  
> **Task Reference:** `SITECONVOY-M0-002`  
> This matrix outlines the comprehensive scenarios, data mappings, edge cases, conditional/unsupported topologies, and high-level acceptance requirements for all four migration modes targeted for SiteConvoy version 1.0.

---

## 1. Comprehensive Mode Matrix

| Mode | Direction | Source Scope | Target Scope | Core Transformations & Key Acceptance Requirements |
|---|---|---|---|---|
| **Mode 1** | Multisite Subsite &rarr; Standalone | Single subsite (`wp_{id}_*`) or Main site (`wp_*`) | Independent standalone WordPress | Extract subsite tables; normalize prefix to `wp_*`; filter network users; translate uploads from `sites/{id}/` to root `uploads/`; rewrite domain and path URLs with serialization support while preserving `wp_posts.guid`. Special extraction logic for Site ID 1 without `ms_global` network metadata. |
| **Mode 2** | Multisite Subsite &rarr; Target Multisite | Single subsite (`wp_{source_id}_*`) | Subsite in existing network (`wp_{target_id}_*`) | Re-prefix tables to allocated blog ID; identity-resolution review for collisions; prohibit silent account linking or privilege escalation; relocate media to `uploads/sites/{target_id}/`; verify active plugins. |
| **Mode 3** | Full Multisite Network &rarr; New Environment | Entire network (all sites, sitemeta, global tables, themes, plugins, mu-plugins, drop-ins) | New hosting / domain environment | Preserve all blog IDs and table prefixes; batch-replace network domain, site domains, and paths across all sites (leaving `wp_posts.guid` unmodified); migrate entire uploads tree; transfer/validate complete asset inventory; update `wp-config.php` network constants (`DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`). |
| **Mode 4** | Standalone WordPress &rarr; Multisite Subsite | Full standalone site (`wp_*`) | New subsite in existing network (`wp_{new_id}_*`) | Allocate next available blog ID; rewrite standalone table prefix to `wp_{new_id}_*`; integrate standalone users into network `wp_users` via explicit conflict review; relocate uploads to `uploads/sites/{new_id}/`. |

---

## 2. Detailed Technical Domain Specifications

### 2.1 Network Routing Configurations
- **Subdomain Installations** (`sub.example.com`):
  - Must preserve or accurately transform subdomain routing across all supported modes.
  - Verification across mapped custom domains (e.g., `customdomain.com` mapped to subsite).
- **Subdirectory Installations** (`example.com/site1/`):
  - Must accurately update path routing rules and handle path prefix changes (e.g., `/site1/` to `/` for standalone conversion).

### 2.2 User & Identity Management
- **User Selection (Subsite to Standalone)**:
  - Only users with explicit roles or authored content on the migrated subsite must be transferred. Unrelated network users are excluded.
  - Role preservation: Imported users retain their original roles (e.g. an Author remains an Author). Automatic elevation to Administrator is prohibited.
  - A destination bootstrap administrator is provisioned via explicit operator setup.
- **Cautious Identity Resolution & Collision Handling (Into Multisite)**:
  - Account identity cannot be inferred safely from matching email or username alone.
  - **Prohibit silent account linking**: Never automatically merge or link source accounts to target network accounts based solely on email or login match.
  - **Prohibit privilege escalation**: Never grant network super-administrator (`manage_network`) privileges or elevated site roles via migration import.
  - **Explicit administrator approval**: Detect all email and username collisions during preflight analysis and generate a mapping proposal requiring explicit confirmation by a trusted network administrator.
  - **Collision resolution options**:
    - If email/username collides and the admin approves mapping: map author records to the existing target user.
    - If distinct user: create a new user record with a non-colliding username/email prefix and remap `post_author`, revisions, and user metadata accordingly.
  - All remapping decisions must be recorded in an import journal before applying database updates.

### 2.3 Database Schema & Prefixes
- Dynamic table prefix rewriting must respect table boundaries and never perform substring replacements on table content.
- Database character sets and collations (`utf8mb4`, `utf8mb4_unicode_ci`, etc.) must be preserved or safely adapted during export and import.
- Auto-increment values, primary keys, and indexes must be preserved.

### 2.4 Media, File Hierarchy & URL Handling
- **Preservation of `wp_posts.guid`**:
  - In accordance with WordPress migration standards (see [WordPress Advanced Administration: Migrating](https://developer.wordpress.org/advanced-administration/upgrade/migrating/) and [WP-CLI `search-replace --skip-columns=guid`](https://developer.wordpress.org/cli/commands/search-replace/)), existing `wp_posts.guid` values are globally unique identifiers (primarily used for RSS readers/feed identification) and **must not be modified** during routine domain or host migrations.
  - Deep URL replacement must be performed on post content (HTML image `src`, `srcset` attributes, and hyperlinks), postmeta (e.g., `_wp_attached_file` relative paths), widget settings, and options tables.
  - Exceptional cases (such as newly generated content or explicit administrative overrides) must be handled separately with explicit warning and isolated reasoning.
- **Media Path Translation**:
  - Standalone: `wp-content/uploads/`
  - Multisite: `wp-content/uploads/sites/{blog_id}/`
  - Legacy Multisite: `wp-content/blogs.dir/{blog_id}/files/`
- Translocated media files must be synchronized with database options and attachment metadata without altering existing attachment GUIDs.

### 2.5 Asset Provisioning & Dependency Rules
- **Themes & Child Themes**:
  - Parent themes are mandatory whenever an active or network-enabled child theme is transferred.
- **Plugins & Network Activation**:
  - Sitewide active plugins must be provisioned and verified network-active on the target; missing plugin binaries trigger preflight warnings or dependency blocking.
- **Drop-Ins & Must-Use Plugins**:
  - Preflight validates drop-in compatibility with the target environment; incompatible drop-ins (e.g. host-bound object caches) block migration before mutating files.

### 2.6 Integrity & Verification Requirements
- Preflight validation must simulate the operation and confirm available disk space and permissions before making irreversible changes.
- Cryptographic SHA-256 checksums verify internal package integrity; authentic source trust is verified out-of-band or via explicit operator approval.
- Pre-migration backup checkpoints must be established.
- Failures must trigger staged compensation and rollback procedures where safe, and record clear diagnostic logs for manual recovery if rollback is obstructed.

---

## 3. Conditional, Edge-Case & Unsupported Topologies

| Scenario / Topology | Classification | Impact & Planned Handling |
|---|---|---|
| **Site ID 1 Subsite Extraction** | **Supported with Isolation Adapter** | Dedicated adapter isolates `wp_*` subsite tables from `ms_global` network tables (`wp_blogs`, `wp_blogmeta`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`) and legacy multisite tables (`wp_blog_versions`, `wp_sitecategories`), and scrubs network option keys. |
| **Subdirectory with Port/Path Mapping** | **Supported** | Path search-and-replace engine accounts for custom subfolder installations (e.g. `example.com/wp/site1/`). |
| **Legacy `blogs.dir` Media Paths** | **Conditional** | Preflight flags legacy directory structure and executes rewrite normalization to `uploads/sites/{id}/`. |
| **External Cloud Object Storage (S3/GCS)** | **Conditional / Blocked if Incomplete** | When media is offloaded to S3/GCS and local files are absent: requires verified export adapter and cloud credentials. If cloud sync is unconfigured, migration is **BLOCKED / FLAGGED INCOMPLETE** with clear diagnostics. Silent partial success is prohibited; object storage secrets are excluded from packages. |
| **Host-Dependent Drop-Ins (e.g. `object-cache.php`)** | **Conditional / Blocked if Incompatible** | Drop-ins binding to local host sockets or unavailable PHP extensions trigger a preflight warning and block deployment to prevent fatal site crashes. |
| **Custom User Tables (`CUSTOM_USER_TABLE`)** | **Unsupported in v1.0** | Environments using non-standard shared user tables are detected and halted during preflight with explicit warning. |
| **Multi-Network Configurations (`site_id > 1`)** | **Unsupported in v1.0** | Multiple networks within a single database are flagged during preflight; migration of non-primary network is blocked. |
| **Non-Standard Database Engines (e.g. MyISAM)** | **Conditional** | Non-transactional tables trigger strict pre-migration table backup checkpoints prior to staging. |
