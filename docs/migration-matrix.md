# SiteConvoy Migration Matrix

> [!NOTE]
> **Status: PROPOSED SPECIFICATION**  
> This matrix outlines the scenarios, data mappings, and high-level acceptance requirements for all four migration modes targeted for SiteConvoy version 1.0. All requirements will be validated with reproducible automated test fixtures in future milestones.

---

## Comprehensive Mode Matrix

| Mode | Direction | Source Scope | Target Scope | Key Challenges & Requirements |
|---|---|---|---|---|
| **Mode 1** | Multisite Subsite &rarr; Standalone | Single subsite (`wp_{id}_*`) or Main site (`wp_*`) | Independent standalone WordPress | Extract subsite tables; normalize prefix to `wp_*`; filter network users; translate uploads from `sites/{id}/` to root `uploads/`; rewrite domain and path URLs with serialization support. Special extraction logic for Site ID 1 without network metadata. |
| **Mode 2** | Multisite Subsite &rarr; Target Multisite | Single subsite (`wp_{source_id}_*`) | Subsite in existing network (`wp_{target_id}_*`) | Re-prefix tables to new blog ID; match or remap user accounts; resolve user ID collisions; relocate media to `uploads/sites/{target_id}/`; merge or verify active plugins. |
| **Mode 3** | Full Multisite Network &rarr; New Environment | Entire network (all sites, sitemeta, global tables) | New hosting / domain environment | Preserve all blog IDs and table prefixes; batch-replace network domain, site domains, and paths across all sites; migrate entire uploads tree; update `wp-config.php` network constants (`DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`). |
| **Mode 4** | Standalone WordPress &rarr; Multisite Subsite | Full standalone site (`wp_*`) | New subsite in existing network (`wp_{new_id}_*`) | Allocate next available blog ID; rewrite standalone table prefix to `wp_{new_id}_*`; integrate standalone users into network `wp_users`; resolve user collisions; relocate uploads to `uploads/sites/{new_id}/`. |

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
  - Super Administrator accounts must only be transferred if they hold a role on the specific subsite or authored content.
- **Collision Resolution (Into Multisite)**:
  - If a user with the same email exists on the target network: link the existing user and assign appropriate subsite role.
  - If a username collides with differing email: provide configurable resolution (rename username or map to distinct user) without data loss.
  - All author IDs in post records (`post_author`) and revision history must be updated to match the final resolved target user ID.

### 3. Database Schema & Prefixes
- Dynamic table prefix rewriting must respect table boundaries and never perform substring replacements on table content.
- Database character sets and collations (`utf8mb4`, `utf8mb4_unicode_ci`, etc.) must be preserved or safely adapted during export and import.
- Auto-increment values and indexes must be preserved.

### 4. Media and File Hierarchy
- Source uploads must be accurately mapped to target upload conventions:
  - Standalone: `wp-content/uploads/`
  - Multisite: `wp-content/uploads/sites/{blog_id}/`
  - Legacy Multisite: `wp-content/blogs.dir/{blog_id}/files/`
- Deep references in post content (HTML image `src`, `srcset` attributes, attachment GUIDs, and metadata) must be updated to match target URLs.

### 5. Integrity & Verification Requirements
- Preflight validation must simulate the operation and confirm available disk space and permissions before making irreversible changes.
- Cryptographic SHA-256 checksums must verify archive package integrity prior to restoration.
- Automated rollback must be triggered if any step in the migration process fails.
