# SiteConvoy Migration Matrix

> [!NOTE]
> **Status: PROPOSED SPECIFICATION**  
> This matrix outlines the scenarios, data mappings, and high-level acceptance requirements for all four migration modes targeted for SiteConvoy version 1.0. All requirements represent design goals and specifications to be validated with reproducible automated test fixtures in future milestones.

---

## Comprehensive Mode Matrix

| Mode | Direction | Source Scope | Target Scope | Key Challenges & Requirements |
|---|---|---|---|---|
| **Mode 1** | Multisite Subsite &rarr; Standalone | Single subsite (`wp_{id}_*`) or Main site (`wp_*`) | Independent standalone WordPress | Extract subsite tables; normalize prefix to `wp_*`; filter network users; translate uploads from `sites/{id}/` to root `uploads/`; rewrite domain and path URLs with serialization support (preserving `wp_posts.guid`). Special extraction logic for Site ID 1 without network metadata. |
| **Mode 2** | Multisite Subsite &rarr; Target Multisite | Single subsite (`wp_{source_id}_*`) | Subsite in existing network (`wp_{target_id}_*`) | Re-prefix tables to new blog ID; identity-resolution review for collisions; prohibit silent account linking; relocate media to `uploads/sites/{target_id}/`; merge or verify active plugins. |
| **Mode 3** | Full Multisite Network &rarr; New Environment | Entire network (all sites, sitemeta, global tables) | New hosting / domain environment | Preserve all blog IDs and table prefixes; batch-replace network domain, site domains, and paths across all sites (leaving `wp_posts.guid` unmodified); migrate entire uploads tree; update `wp-config.php` network constants (`DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`). |
| **Mode 4** | Standalone WordPress &rarr; Multisite Subsite | Full standalone site (`wp_*`) | New subsite in existing network (`wp_{new_id}_*`) | Allocate next available blog ID; rewrite standalone table prefix to `wp_{new_id}_*`; integrate standalone users into network `wp_users` via explicit conflict review; relocate uploads to `uploads/sites/{new_id}/`. |

---

## Detailed Acceptance Requirements by Domain

### 1. Network Routing Configurations
- **Subdomain Installations** (`sub.example.com`):
  - Must preserve or accurately transform subdomain routing across modes.
  - Verification across mapped custom domains (e.g., `customdomain.com` mapped to subsite).
- **Subdirectory Installations** (`example.com/site1/`):
  - Must accurately update path routing rules and handle path prefix changes (e.g., `/site1/` to `/` for standalone conversion).

### 2. User & Identity Management
- **User Selection (Subsite to Standalone)**:
  - Only users with explicit roles or authored content on the migrated subsite must be transferred.
  - Super Administrator accounts must only be transferred if they hold an explicit role on the specific subsite or authored content.
- **Cautious Identity Resolution & Collision Handling (Into Multisite)**:
  - Account identity cannot be inferred safely from matching email or username alone.
  - **Prohibit silent account linking**: Never automatically merge or link source accounts to target network accounts based solely on email or login match.
  - **Prohibit privilege escalation**: Never grant network super-administrator (`manage_network`) privileges or elevated site roles via migration import.
  - **Explicit administrator approval**: Detect all email and username collisions during preflight analysis and generate a mapping proposal requiring explicit confirmation by a trusted network administrator.
  - **Collision resolution options**:
    - If email/username collides and the admin approves mapping: map author records to the existing target user.
    - If distinct user: create a new user record with a non-colliding username/email prefix and remap `post_author`, revisions, and user metadata accordingly.
  - All remapping decisions must be recorded in an import journal before applying database updates.

### 3. Database Schema & Prefixes
- Dynamic table prefix rewriting must respect table boundaries and never perform substring replacements on table content.
- Database character sets and collations (`utf8mb4`, `utf8mb4_unicode_ci`, etc.) must be preserved or safely adapted during export and import.
- Auto-increment values, primary keys, and indexes must be preserved.

### 4. Media, File Hierarchy & URL Handling
- **Preservation of `wp_posts.guid`**:
  - In accordance with WordPress migration standards (see [WordPress Advanced Administration: Migrating](https://developer.wordpress.org/advanced-administration/upgrade/migrating/) and [WP-CLI `search-replace --skip-columns=guid`](https://developer.wordpress.org/cli/commands/search-replace/)), existing `wp_posts.guid` values are globally unique identifiers (primarily used for RSS readers/feed identification) and **must not be modified** during routine domain or host migrations.
  - Deep URL replacement must be performed on post content (HTML image `src`, `srcset` attributes, and hyperlinks), postmeta (e.g., `_wp_attached_file` relative paths), widget settings, and options tables.
  - Exceptional cases (such as newly generated content or explicit administrative overrides) must be handled separately with explicit warning and isolated reasoning.
- **Media Path Translation**:
  - Standalone: `wp-content/uploads/`
  - Multisite: `wp-content/uploads/sites/{blog_id}/`
  - Legacy Multisite: `wp-content/blogs.dir/{blog_id}/files/`
- Translocated media files must be synchronized with database options and attachment metadata without altering existing attachment GUIDs.

### 5. Integrity & Verification Requirements
- Preflight validation must simulate the operation and confirm available disk space and permissions before making irreversible changes.
- Cryptographic SHA-256 checksums must verify archive package integrity prior to restoration.
- Pre-migration backup checkpoints must be established.
- Failures must trigger staged compensation and rollback procedures where safe, and record clear diagnostic logs for manual recovery if rollback is obstructed.
