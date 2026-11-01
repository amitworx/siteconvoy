# SiteConvoy Migration Feasibility Analysis

> [!NOTE]
> **Task Reference:** `SITECONVOY-M0-002`  
> **Status:** PROPOSED FEASIBILITY SPECIFICATION  
> Independent feasibility evaluations, prerequisites, transformation pipelines, risk assessments, and recommendations for all four v1.0 migration directions.

---

## 1. Executive Feasibility Matrix

| Operation | Feasibility | Primary Complexity / Gate | Recommended Approach | Confidence Level |
|---|---|---|---|---|
| **Direction 1: Multisite Subsite &rarr; Standalone** | **HIGH** (Standard) / **MEDIUM** (Site ID 1) | Prefix normalization (`wp_{id}_` to `wp_`); user filtering; uploads path shift; Site ID 1 global table isolation. | Chunked SQL extraction, table rename, user capability rewrite, media translocation. | 95% (Subsite) / 85% (Site ID 1) |
| **Direction 2: Multisite Subsite &rarr; Another Multisite** | **MEDIUM** | User ID collisions; target blog ID allocation; prefix rewriting; media relocation to target site folder. | Preflight user collision detection; explicit admin mapping review; author ID re-indexing; staged import. | 80% |
| **Direction 3: Full Multisite Network &rarr; New Environment** | **HIGH** | Batch domain/path replacements across all site tables and sitemeta; uploads tree relocation; `wp-config.php` constants. | Full database dump with selective serialized search-and-replace (excluding GUIDs); rsync-style media transfer. | 90% |
| **Direction 4: Standalone WordPress &rarr; Multisite Subsite** | **MEDIUM** | Table prefix rewrite (`wp_` to `wp_{new_id}_`); user integration into network `wp_users`; media relocation to `uploads/sites/{new_id}/`. | Preflight collision detection; new user injection with non-colliding logins/emails or admin approval; prefix transformation. | 80% |

---

## 2. Direction 1: Multisite Subsite &rarr; Standalone WordPress

### 2.1 Scope & Prerequisites
- **Source**: Subsite in a healthy WordPress multisite network (subdomain or subdirectory).
- **Target**: Empty or clean standalone WordPress database and filesystem.
- **Special Consideration**: Supports both numbered secondary subsites (`blog_id > 1`) and the network's main site (`blog_id = 1`).

### 2.2 Pipeline & Technical Transformations
1. **Discovery & Preflight**:
   - Resolve source prefix using `wpdb::get_blog_prefix( $blog_id )`.
   - Identify site tables vs network global tables (`wpdb::tables( 'blog' )`).
   - Identify associated users (site capabilities + authors).
2. **Database Transformation**:
   - Rename tables from `{$source_prefix}{table}` to `{$target_base_prefix}{table}` (e.g. `wp_2_posts` &rarr; `wp_posts`).
   - For **Site ID 1**: Retain `wp_posts`, `wp_options`, etc., but explicitly exclude `wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`. Filter `wp_options` to purge `active_sitewide_plugins`.
   - Extract and rename usermeta capabilities: `{$source_prefix}capabilities` &rarr; `{$target_base_prefix}capabilities`.
3. **Media Transformation**:
   - Move/copy files from `wp-content/uploads/sites/{blog_id}/` to standalone `wp-content/uploads/`.
4. **Data Search and Replace**:
   - Replace old subsite domain and path with target standalone domain and path across post content, options, and postmeta.
   - **Strict Preservation**: Preserve all existing `wp_posts.guid` entries unchanged.

### 2.3 Edge Cases & Blockers
- **Blocker**: Network-activated plugins storing settings exclusively in `wp_sitemeta`. *Resolution*: Preflight inspects network-active plugins and flags missing settings.
- **Risk**: Hardcoded root upload paths in legacy content. *Resolution*: Regex-based content path replacement.

---

## 3. Direction 2: Multisite Subsite &rarr; Target Multisite Network

### 3.1 Scope & Prerequisites
- **Source**: Subsite in Source Network A.
- **Target**: Destination Network B with super-admin access.

### 3.2 Pipeline & Technical Transformations
1. **Target Site Allocation**:
   - Destination network creates or allocates a new site record in `wp_blogs`, obtaining `target_blog_id`.
   - Target prefix is derived: `{$target_base_prefix}{$target_blog_id}_`.
2. **Identity Resolution & Collision Handling**:
   - Source subsite users are scanned against Target Network `wp_users`.
   - **Strict Security Rule**: Prohibit silent auto-linking.
   - If an email match occurs, generate a preflight review report. A trusted target network administrator must explicitly review and confirm the mapping.
   - If unconfirmed or distinct, create a new target user with a unique slug and remap `post_author` IDs in all migrated tables to the new user ID.
   - Super-admin privileges are never imported.
3. **Database Transformation**:
   - Rename tables to `{$target_prefix}{table}`.
   - Transform usermeta capabilities to `{$target_prefix}capabilities`.
4. **Media Relocation**:
   - Move uploads directly into `wp-content/uploads/sites/{target_blog_id}/`.
5. **Search and Replace**:
   - Replace source subsite URL with target subsite URL, preserving `wp_posts.guid`.

---

## 4. Direction 3: Full Multisite Network &rarr; New Environment

### 4.1 Scope & Prerequisites
- **Source**: Complete Multisite Network.
- **Target**: Fresh server/hosting environment with matching or upgraded PHP/MySQL stack.

### 4.2 Pipeline & Technical Transformations
1. **Full Database Capture**:
   - Export all global tables (`wp_users`, `wp_usermeta`, `wp_blogs`, `wp_site`, `wp_sitemeta`) and all site tables across all blogs.
2. **Network Routing Search-and-Replace**:
   - Update `wp_site.domain` and `wp_site.path`.
   - Update `wp_blogs.domain` and `wp_blogs.path` for every registered subsite.
   - Update `siteurl` and `home` options in `wp_options` and `wp_{id}_options` across all sites.
   - Preserve `wp_posts.guid` on all posts.
3. **Filesystem Migration**:
   - Transfer entire `wp-content/uploads/` hierarchy preserving `sites/` directory tree.
4. **Configuration Generation**:
   - Generate updated `wp-config.php` network constants:
     - `DOMAIN_CURRENT_SITE`
     - `PATH_CURRENT_SITE`
     - `SITE_ID_CURRENT_SITE`
     - `BLOG_ID_CURRENT_SITE`

### 4.3 Feasibility Assessment
- High feasibility. The primary challenge is archive size and memory/time exhaustion on shared hosting. Addressed via streaming decompression and chunked batch SQL import.

---

## 5. Direction 4: Standalone WordPress &rarr; Multisite Subsite

### 5.1 Scope & Prerequisites
- **Source**: Standalone WordPress install.
- **Target**: Existing Multisite network with available capacity.

### 5.2 Pipeline & Technical Transformations
1. **Site Allocation**:
   - Network administrator registers new subsite on target network (generating `new_blog_id`).
2. **Database Transformation**:
   - Rename source standalone tables (`wp_*`) to `{$target_base_prefix}{$new_blog_id}_*`.
   - Extract source users:
     - Detect username and email collisions against target `wp_users`.
     - Present collision report to administrator.
     - Inject approved users into target `wp_users` and remap IDs.
     - Set `{$target_base_prefix}{$new_blog_id}_capabilities` for imported accounts.
3. **Media Relocation**:
   - Move standalone uploads from `wp-content/uploads/` into target network folder `wp-content/uploads/sites/{new_blog_id}/`.
4. **Search and Replace**:
   - Update site URLs to network subsite domain/path format, leaving GUIDs unchanged.

---

## 6. Shared Engine Architecture vs. Operation-Specific Adapters

To ensure maintainability, SiteConvoy separates common pipeline infrastructure from operation-specific logic:

```
+-------------------------------------------------------------------------+
|                        SiteConvoy Common Engine                         |
|  - Preflight Validator       - Streaming Archiver (Tar/Zip)             |
|  - Serialized Search/Replace  - State Journal & Checkpointing           |
|  - Cryptographic Checksums   - Staged Filesystem Staging                |
+-------------------------------------------------------------------------+
                                    |
      +-----------------------------+-----------------------------+
      |                             |                             |
[Mode 1 Adapter]             [Mode 2 Adapter]              [Mode 3 Adapter]
Subsite -> Standalone        Subsite -> Multisite          Full Network Migration
- Prefix -> base_prefix      - Allocate blog_id            - Global table mapping
- Strip ms_global tables     - Cross-user re-indexing      - wp-config updates
- Uploads -> root uploads    - Uploads -> sites/{id}/      - All-site URL updates
```

---

## 7. Deferred Proof-of-Concept Experiments (Scheduled for M1+)

The following empirical investigations require executable test harnesses and are formally deferred to Milestone M1+:
1. **Large Serialized Data Benchmark**: Verify memory overhead of recursive JSON/serialized search-and-replace engines on 500MB+ options tables.
2. **Chunked Streaming Archive Extraction**: Validate streaming Tar/Gzip decompression under PHP shared-hosting memory constraints (64MB / 128MB).
3. **DDL Transaction Simulation**: Measure failure recovery behavior when `DROP TABLE` or `RENAME TABLE` fails mid-sequence on MariaDB vs MySQL 8.0.
