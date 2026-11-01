# WordPress Core Data Inventory & Schema Boundaries

> [!NOTE]
> **Task Reference:** `SITECONVOY-M0-002`  
> **Status:** PROPOSED RESEARCH SPECIFICATION  
> Sourced from official WordPress core APIs (`wpdb::tables()`, `wpdb::get_blog_prefix()`, `wp_get_db_schema()`) and empirical database analysis. This inventory establishes the foundational boundaries between global network data, site-specific subsite data, shared identities, and third-party plugin tables.

---

## 1. Database Table Scopes & Discovery APIs

WordPress does not use a single rigid prefix across all installations or all sites within a network. Robust discovery must dynamically query core properties rather than making hardcoded assumptions like `wp_` or `wp_2_`.

### 1.1 Core API Scope Definitions (`wpdb::tables()`)

As defined in [`wpdb::tables()`](https://developer.wordpress.org/reference/classes/wpdb/tables/), WordPress segments database tables into distinct functional scopes:

1. **`global` (Global Single/Multisite Tables)**:
   - Shared across all sites in the database installation:
     - `users` (Canonical name: `{$wpdb->base_prefix}users`)
     - `usermeta` (Canonical name: `{$wpdb->base_prefix}usermeta`)
2. **`ms_global` (Multisite Network Global Tables)**:
   - Specific to the multisite network governance and site tracking:
     - `blogs` (`{$wpdb->base_prefix}blogs`) &mdash; Tracks all subsites, blog IDs, domains, and paths.
     - `site` (`{$wpdb->base_prefix}site`) &mdash; Defines the network entity (domain/path).
     - `sitemeta` (`{$wpdb->base_prefix}sitemeta`) &mdash; Network-wide options, active sitewide plugins, network admin list.
     - `signups` (`{$wpdb->base_prefix}signups`) &mdash; Pending user registrations and site activations.
     - `registration_log` (`{$wpdb->base_prefix}registration_log`) &mdash; Historical subsite creation log.
     - `blog_versions` (`{$wpdb->base_prefix}blog_versions`) &mdash; Database schema upgrade tracking per subsite.
3. **`blog` (Site-Specific Subsite Tables)**:
   - Instantiated for every individual site within the network:
     - `posts`
     - `postmeta`
     - `options`
     - `comments`
     - `commentmeta`
     - `terms`
     - `termmeta`
     - `term_taxonomy`
     - `term_relationships`
     - `links` (legacy core table, often inactive)

---

## 2. Dynamic Prefix Resolution & The Main Site (Site ID 1)

### 2.1 Prefix Resolution Logic (`wpdb::get_blog_prefix()`)

The canonical table prefix for any site is resolved via [`wpdb::get_blog_prefix( $blog_id )`](https://developer.wordpress.org/reference/classes/wpdb/get_blog_prefix/):

$$\text{Prefix}(blog\_id) = \begin{cases} wpdb\rightarrow\text{base\_prefix} & \text{if } blog\_id \text{ is null or empty} \\ wpdb\rightarrow\text{base\_prefix} \cdot blog\_id \cdot \text{"\_"} & \text{if } blog\_id > 1 \text{ in standard multisite} \\ wpdb\rightarrow\text{base\_prefix} & \text{for Site ID 1 (standard main site)} \end{cases}$$

*Example with default prefix (`$wpdb->base_prefix = 'wp_'`)*:
- Site 1 Posts: `wp_posts`
- Site 2 Posts: `wp_2_posts`
- Site 42 Posts: `wp_42_posts`

*Example with custom nondefault prefix (`$wpdb->base_prefix = 'prod_sec_'`)*:
- Site 1 Posts: `prod_sec_posts`
- Site 2 Posts: `prod_sec_2_posts`
- Global Users: `prod_sec_users`
- Network Blogs: `prod_sec_blogs`

### 2.2 The Main Site (Site ID 1) Asymmetry Problem

A critical architectural pitfall in naive multisite migration tools is treating Site ID 1 as identical to secondary subsites or confusing it with standalone WordPress:

1. **Shared Root Prefix**: The main site (Site ID 1) does *not* have a numerical prefix (it is not `wp_1_posts`, but `wp_posts`).
2. **Co-location with Global Tables**: Site 1's tables (`wp_posts`, `wp_options`, `wp_terms`) reside in the exact same table namespace as network global tables (`wp_users`, `wp_blogs`, `wp_site`, `wp_sitemeta`).
3. **Options Bleed**: Site 1's `wp_options` table contains standard subsite options (`siteurl`, `home`, `blogname`), but frequently holds historical settings or plugin data intended for the entire network before multisite was enabled.
4. **Extraction Requirement**: When extracting Site ID 1 into a standalone WordPress site, SiteConvoy **must** explicitly filter out the `ms_global` tables (`wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`, `wp_blog_versions`) and scrub network-only keys from `wp_options` (e.g., `active_sitewide_plugins`).

---

## 3. User Identity, Roles, and Capabilities Storage

### 3.1 Global Identity vs. Scoped Capabilities

- **Account Credentials (`wp_users`)**: User accounts (username, password hash, email, registration timestamp, display name) are stored globally in `{$wpdb->base_prefix}users`. There are no site-specific user credential tables.
- **Roles and Permissions (`wp_usermeta`)**: A user's capabilities on a specific site are stored as serialized arrays in `{$wpdb->base_prefix}usermeta` using a dynamic meta key:
  - Main site (Site 1): `{$wpdb->base_prefix}capabilities` and `{$wpdb->base_prefix}user_level`
  - Subsite (e.g. Site 2): `{$wpdb->base_prefix}2_capabilities` and `{$wpdb->base_prefix}2_user_level`
  - Network Super Administrators: Defined globally in `{$wpdb->base_prefix}sitemeta` under the option key `site_admins`.

### 3.2 User Extraction Boundary

When migrating a subsite (Direction 1 or 2):
1. **Inclusion Criteria**: A user record is included in the migration package *if and only if*:
   - The user has an explicit capability entry for that site (`{$blog_prefix}capabilities`), OR
   - The user is the author of at least one post, page, custom post type, or attachment on that site (`post_author = $user_id`), OR
   - The user is assigned to content (e.g. revision author, comment author with matching user ID).
2. **Data Minimization**: All other network users who have no relationship to the migrating subsite are excluded to prevent user data leakage.

---

## 4. Media and Filesystem Topology

### 4.1 Modern Multisite (`wp-content/uploads/sites/{id}/`)
- Core WordPress multisite isolates media uploads by site ID under `wp-content/uploads/sites/{id}/YYYY/MM/filename.ext`.
- Database references in `wp_{id}_postmeta` store relative paths in `_wp_attached_file` (e.g., `2026/11/photo.jpg`), while post content HTML contains full URLs (e.g., `https://example.com/wp-content/uploads/sites/2/2026/11/photo.jpg`).

### 4.2 Legacy Multisite (`wp-content/blogs.dir/{id}/files/`)
- Networks created prior to WordPress 3.5 and not migrated use `wp-content/blogs.dir/{id}/files/` with Apache/Nginx rewrite rules mapping `/files/YYYY/MM/...` to the physical directory.
- *Handling*: Must be detected during preflight inspection and normalized to standard upload paths during extraction.

---

## 5. Plugin-Custom Tables and Ambiguous Data

### 5.1 Discovery and Classification

Third-party plugins often create custom tables that do not adhere to WordPress core conventions. SiteConvoy adopts a strict three-tier classification:

1. **Known Site-Specific Table**: Table name begins with `{$blog_prefix}` followed by non-core suffix (e.g., `wp_2_woocommerce_order_items`). *Action*: Candidate for migration with warning.
2. **Known Network-Global Table**: Table name begins with `{$wpdb->base_prefix}` but has no site ID (e.g., `wp_redirection_items`). *Action*: If extracting a subsite, this table is ambiguous; require administrator review.
3. **Unowned / Orphaned Table**: Table does not match base prefix or site prefix. *Action*: Flag during preflight check; do NOT copy blindly without explicit admin confirmation.

### 5.2 Nonstandard Topologies & Unsupported Environments

The following configurations require explicit detection and conditional rejection or manual intervention:
- **`CUSTOM_USER_TABLE` or `CUSTOM_USER_META_TABLE`**: WordPress installations configured with external or shared user databases outside `$wpdb->base_prefix`.
- **Multi-Network Installations**: Networks with multiple network IDs (`site_id > 1`).
- **External Object Storage (S3 / Cloud Storage Plugins)**: Offloaded media where physical files do not exist on the local filesystem.
