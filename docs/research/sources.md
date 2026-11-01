# Primary Source Bibliography — SiteConvoy Architecture & Feasibility

**Task Reference:** `SITECONVOY-M0-002`  
**Date of Verification:** 2026-11-01  
**Scope:** Authoritative primary sources from official WordPress documentation, WordPress Core source code, and WP-CLI commands underpinning the architecture and feasibility specifications.

---

## Authoritative Primary Sources

| # | Topic / Scope | Primary URL | HTTP Verification | Summary of Authoritative Content |
|---|---|---|---|---|
| 1 | Table Prefix Resolution | https://developer.wordpress.org/reference/classes/wpdb/get_blog_prefix/ | 200 OK (2026-11-01) | Canonical method: If `$blog_id` is null, uses current `$wpdb->blogid`. In regular multisite (`MULTISITE` defined), blog ID 0 or 1 returns `base_prefix`, while other IDs return `base_prefix . blog_id . '_'`. Single-site always returns `base_prefix`. |
| 2 | Core Database Tables API | https://developer.wordpress.org/reference/classes/wpdb/tables/ | 200 OK (2026-11-01) | Returns array of core tables categorized by scope: `'all'`, `'blog'`, `'global'`, `'ms_global'`, and `'old'`. Note that `$scope = 'global'` on multisite merges `$this->global_tables` and `$this->ms_global_tables`, whereas `'ms_global'` explicitly targets network governance. |
| 3 | Core Database Schema | https://developer.wordpress.org/reference/functions/wp_get_db_schema/ | 200 OK (2026-11-01) | Defines canonical table schemas across `'all'`, `'blog'`, and `'global'` scopes, determining default indices, collations, and columns. |
| 4 | WordPress Database Class | https://developer.wordpress.org/reference/classes/wpdb/ | 200 OK (2026-11-01) | Documents `$wpdb` properties: `$prefix`, `$base_prefix`, `$blogid`, `$siteid`, table mappings, and character collation settings. |
| 5 | Single Site Migration Guide | https://developer.wordpress.org/advanced-administration/upgrade/migrating/ | 200 OK (2026-11-01) | Canonical migration guidelines establishing that `wp_posts.guid` represents a unique feed/RSS identifier, never to be rewritten during routine site migrations. |
| 6 | URL / Data Search & Replace | https://developer.wordpress.org/cli/commands/search-replace/ | 200 OK (2026-11-01) | Authoritative reference for serialized PHP search-and-replace, multi-byte character safety, and the essential `--skip-columns=guid` flag. |
| 7 | Network Configuration Constants | https://developer.wordpress.org/advanced-administration/wordpress/wp-config/ | 200 OK (2026-11-01) | Defines multisite configuration constants (`MULTISITE`, `SUBDOMAIN_INSTALL`, `DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`, `SITE_ID_CURRENT_SITE`, `BLOG_ID_CURRENT_SITE`). |
| 8 | Multisite Administration & Architecture | https://developer.wordpress.org/advanced-administration/multisite/ | 200 OK (2026-11-01) | Handbook detailing network administration, subsite uploads hierarchy (`wp-content/uploads/sites/{id}/`), user capabilities, and subsite structures. |

*(Note: The legacy URL `https://developer.wordpress.org/advanced-administration/upgrade/migrating-multisite/` returned 404 Not Found upon access testing on 2026-11-01 and has been formally superseded by the active Multisite Administration Handbook at `https://developer.wordpress.org/advanced-administration/multisite/` and the Single-Site Migration Guide).*

---

## Claim-to-Source Mapping

1. **Table Discovery & Prefix Boundaries**:
   - *Claim*: Table prefixes cannot be assumed to be hardcoded `wp_` or `wp_{id}_`. In multisite, `wpdb::get_blog_prefix()` evaluates `$this->blogid` when null, `base_prefix` for ID 0 or 1, and `base_prefix . blog_id . '_'` for others.
   - *Source*: [wpdb::get_blog_prefix()](https://developer.wordpress.org/reference/classes/wpdb/get_blog_prefix/) and [wpdb::tables()](https://developer.wordpress.org/reference/classes/wpdb/tables/).
2. **Main Site (Site ID 1) Table Structure**:
   - *Claim*: Site ID 1 subsite tables share the `$wpdb->base_prefix` without an embedded blog ID number (e.g., `wp_posts`), existing alongside global network tables (`wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`, `wp_blog_versions`).
   - *Source*: [wpdb::tables()](https://developer.wordpress.org/reference/classes/wpdb/tables/) and core `wp-includes/wp-db.php`.
3. **GUID Preservation Requirement**:
   - *Claim*: Existing `wp_posts.guid` entries must remain unchanged during routine domain and host migrations because they are immutable feed identifiers.
   - *Source*: [WordPress Migrating Guide](https://developer.wordpress.org/advanced-administration/upgrade/migrating/) and [WP-CLI search-replace](https://developer.wordpress.org/cli/commands/search-replace/).
4. **Media Path Hierarchy**:
   - *Claim*: Modern multisite installations store subsite media in `wp-content/uploads/sites/{blog_id}/` (or legacy `wp-content/blogs.dir/{blog_id}/files/`), whereas standalone installations store uploads in `wp-content/uploads/`.
   - *Source*: [WordPress Multisite Administration Handbook](https://developer.wordpress.org/advanced-administration/multisite/).
5. **Shared User Storage and Scoped Roles**:
   - *Claim*: Multisite networks share a single `wp_users` table across all sites, while roles and capabilities are scoped per-site within `wp_usermeta` using the meta key format `{$blog_prefix}capabilities`.
   - *Source*: [wpdb::get_blog_prefix()](https://developer.wordpress.org/reference/classes/wpdb/get_blog_prefix/) and core `WP_User` class.

---

## Open Research Questions & Unresolved Topologies

1. **Multi-Network Installations (Networks within Networks)**:
   - WordPress core schema supports multiple networks via `wp_site` and `wp_blogs.site_id`, but standard core administrative UI only exposes single-network operation. Migration across multi-network environments requires further verification in milestone M1.
2. **Nonstandard Custom User Tables**:
   - Custom configurations defining `CUSTOM_USER_TABLE` and `CUSTOM_USER_META_TABLE` alter core user relationships. SiteConvoy v1.0 marks environments with external custom user tables as conditional/unsupported pending explicit mapping hooks.
3. **Legacy `blogs.dir` Rewrite Rules**:
   - Multisite installations upgraded from WordPress 3.4 or earlier may retain rewrite-based media paths (`/files/`) rather than direct `/sites/{id}/` URLs. Feasibility requires testing translation adapters.
4. **Offloaded Cloud Media Incomplete Migration**:
   - Unsynchronized S3/GCS media references require explicit export adapters or blocking preflight checks to prevent silent data loss.
