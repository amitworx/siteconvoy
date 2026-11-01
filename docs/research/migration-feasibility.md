# SiteConvoy Migration Feasibility Analysis

> [!NOTE]
> **Task Reference:** `SITECONVOY-M0-002`  
> **Status:** PROPOSED FEASIBILITY SPECIFICATION  
> Independent feasibility evaluations, prerequisites, transformation pipelines, risk assessments, and recommendations for all four v1.0 migration directions. Numerical confidence percentages are intentionally avoided; feasibility is classified qualitatively based on verified core behavior and identified proof-of-concept gates.

---

## 1. Executive Feasibility Matrix

| Operation | Qualitative Feasibility | Primary Complexity / Gate | Recommended Approach | Key Proof-of-Concept Gates (M1+) |
|---|---|---|---|---|
| **Direction 1: Multisite Subsite &rarr; Standalone** | **HIGH** (Standard Subsite) / **MODERATE** (Site ID 1) | Prefix normalization (`wp_{id}_` to `wp_`); user filtering; uploads path shift; Site ID 1 `ms_global` table isolation. | Chunked SQL extraction, table rename, user capability rewrite, media translocation. | Memory benchmarking on large serialized options; Site ID 1 table isolation test. |
| **Direction 2: Multisite Subsite &rarr; Another Multisite** | **MODERATE** | User ID collisions; target blog ID allocation; prefix rewriting; media relocation to target site folder. | Preflight user collision detection; explicit admin mapping review; author ID re-indexing; staged import. | Collision remapping engine; role verification gate. |
| **Direction 3: Full Multisite Network &rarr; New Environment** | **HIGH** | Batch domain/path replacements across all site tables and sitemeta; uploads tree relocation; `wp-config.php` constants; complete network table inventory. | Full database dump with selective serialized search-and-replace (excluding GUIDs); streaming media transfer. | Large multi-site archive streaming; non-transactional DDL recovery. |
| **Direction 4: Standalone WordPress &rarr; Multisite Subsite** | **MODERATE** | Table prefix rewrite (`wp_` to `wp_{new_id}_`); user integration into network `wp_users`; media relocation to `uploads/sites/{new_id}/`. | Preflight collision detection; new user injection with non-colliding logins/emails or admin approval; prefix transformation. | Standalone admin mapping without network elevation; prefix rewrite validator. |

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
   - For **Site ID 1**: Retain `wp_posts`, `wp_options`, etc., but explicitly exclude all `ms_global` tables (`wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`, `wp_blog_versions`). Filter `wp_options` to purge `active_sitewide_plugins`.
   - Extract and rename usermeta capabilities: `{$source_prefix}capabilities` &rarr; `{$target_base_prefix}capabilities`.
3. **Media Transformation**:
   - Move/copy files from `wp-content/uploads/sites/{blog_id}/` to standalone `wp-content/uploads/`.
4. **Data Search and Replace**:
   - Replace old subsite domain and path with target standalone domain and path across post content, options, and postmeta.
   - **Strict Preservation**: Preserve all existing `wp_posts.guid` entries unchanged.

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

### 4.1 Scope & Complete Network Table Inventory
Full network migration encompasses the complete network database topology:
- **Shared User Tables (`global`)**: `{$base_prefix}users`, `{$base_prefix}usermeta`.
- **Multisite Global Governance Tables (`ms_global`)**:
  - `{$base_prefix}blogs` &mdash; Subsite registration records.
  - `{$base_prefix}site` &mdash; Network domain/path definition.
  - `{$base_prefix}sitemeta` &mdash; Network-wide options, active sitewide plugins, admin list.
  - `{$base_prefix}signups` &mdash; Pending user registrations and site activation keys.
  - `{$base_prefix}registration_log` &mdash; Historical subsite creation ledger.
  - `{$base_prefix}blog_versions` &mdash; Schema upgrade tracking per blog.
- **Site-Specific Tables (`blog`)**: All tables for Site 1 (`{$base_prefix}{table}`) and all secondary subsites (`{$base_prefix}{id}_{table}`).
- **Custom / Third-Party Tables**: Plugin tables matching network or subsite naming conventions.

### 4.2 Pipeline & Technical Transformations
1. **Full Database Capture**: Export all global, `ms_global`, and site-specific tables.
2. **Network Routing Search-and-Replace**:
   - Update `wp_site.domain` and `wp_site.path`.
   - Update `wp_blogs.domain` and `wp_blogs.path` for every registered subsite.
   - Update `siteurl` and `home` options across all sites.
   - Preserve `wp_posts.guid` on all posts across all sites.
3. **Filesystem Migration**:
   - Transfer entire `wp-content/uploads/` hierarchy preserving `sites/` directory tree.
4. **Configuration Generation**:
   - Generate updated `wp-config.php` network constants (`DOMAIN_CURRENT_SITE`, `PATH_CURRENT_SITE`).

---

## 5. Direction 4: Standalone WordPress &rarr; Multisite Subsite

### 5.1 Scope & Prerequisites
- **Source**: Standalone WordPress install.
- **Target**: Existing Multisite network with available capacity.

### 5.2 Pipeline & Technical Transformations
1. **Site Allocation**:
   - Network super-administrator registers new subsite on target network (generating `new_blog_id`).
2. **Database Transformation**:
   - Rename source standalone tables (`wp_*`) to `{$target_base_prefix}{$new_blog_id}_*`.
   - Extract source users:
     - Detect username and email collisions against target `wp_users`.
     - Present collision report to administrator.
     - Inject approved users into target `wp_users` and remap IDs.
     - Set `{$target_base_prefix}{$new_blog_id}_capabilities` for imported accounts.
     - Assign site administrator role based on explicit target-admin review (never granting network super-admin rights).
3. **Media Relocation**:
   - Move standalone uploads from `wp-content/uploads/` into target network folder `wp-content/uploads/sites/{new_blog_id}/`.
4. **Search and Replace**:
   - Update site URLs to network subsite domain/path format, leaving GUIDs unchanged.

---

## 6. Shared Engine Architecture vs. Operation-Specific Adapters

To ensure maintainability and testability, SiteConvoy separates common pipeline infrastructure from operation-specific logic across all four modes:

```
+-----------------------------------------------------------------------------------+
|                            SiteConvoy Common Engine                               |
|  - Preflight & Discovery Validator     - Streaming Archiver (Tar/Zip)             |
|  - Serialized Data Search/Replace       - State Journal & Pre-batch Checkpointing  |
|  - Checksum Verification & Trust Review - Staged Staging Directory Sandbox         |
+-----------------------------------------------------------------------------------+
                                          |
      +-------------------+---------------+-------------------+-------------------+
      |                   |                                   |                   |
[Mode 1 Adapter]    [Mode 2 Adapter]                    [Mode 3 Adapter]    [Mode 4 Adapter]
Subsite -> Standalone Subsite -> Multisite              Full Network        Standalone -> Subsite
- Prefix -> base    - Allocate blog_id                  - Global & ms_global- Prefix -> {id}_*
- Strip ms_global   - Cross-user review & re-indexing   - All-site URL maps - Inject into wp_users
- Uploads -> root   - Uploads -> sites/{target_id}/     - wp-config updates - Uploads -> sites/{id}/
```

---

## 7. Source Consistency & Live Writes Analysis

When migrating sites under live traffic, ongoing source mutations pose significant integrity risks:

### 7.1 Mutation Risks During Batch Export
1. **Database / Media Skew**: A post referencing a newly uploaded image is exported, but the media file is created *after* the uploads archiver has finished scanning. Result: Broken media attachment on destination.
2. **Comment / Post Inconsistency**: A new post is published during export, but its associated comments or taxonomy terms are captured in a later chunk, leading to orphaned foreign keys or inconsistent counts.
3. **Session & Auth Drift**: User password updates during export can result in invalid password hashes or invalidated sessions on the destination.

### 7.2 Consistency Mechanisms & Environmental Boundaries
- **Maintenance / Read-Only Window**:
  - *Mechanism*: Programmatically set the migrating site into maintenance mode (e.g. via drop-in or option flag) preventing new posts, comments, or media uploads.
  - *Environmental Reality*: Fully effective on dedicated or controlled environments; user-facing caveats required.
- **Database Snapshots**:
  - Where supported (e.g. MySQL transactional read views `START TRANSACTION WITH CONSISTENT SNAPSHOT`), ensure database tables are dumped from a single coherent point in time. Note that DDL statements and non-InnoDB tables break consistent snapshots.
- **Shared Hosting Limitations**:
  - On constrained shared hosting without snapshot capabilities or CLI access, SiteConvoy **cannot universally guarantee** zero-mutation consistency if the site remains active.
  - *Policy*: Clearly flag this condition during preflight, recommend an off-peak maintenance window, and record an explicit timestamped journal entry warning the operator of potential live-write drift.

---

## 8. Deferred Proof-of-Concept Experiments (Scheduled for M1+)

The following empirical investigations require executable test harnesses and are formally deferred to Milestone M1+:
1. **Large Serialized Data Benchmark**: Verify memory overhead of recursive JSON/serialized search-and-replace engines on 500MB+ options tables.
2. **Chunked Streaming Archive Extraction**: Validate streaming Tar/Gzip decompression under PHP shared-hosting memory constraints (64MB / 128MB).
3. **DDL Transaction Simulation**: Measure failure recovery behavior when `DROP TABLE` or `RENAME TABLE` fails mid-sequence on MariaDB vs MySQL 8.0.
4. **Live Mutation Skew Test**: Simulate simultaneous post publishing during multi-step batch export to measure manifest discrepancies.
