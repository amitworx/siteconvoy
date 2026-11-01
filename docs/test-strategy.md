# SiteConvoy Testing Strategy & Fixture Specification

> [!NOTE]
> **Task Reference:** `SITECONVOY-M0-002`  
> **Status:** PROPOSED TESTING STRATEGY  
> This specification outlines the automated testing pyramid, environment requirements, synthetic fixtures, fault injection scenarios, and test-driven development (TDD) gates planned for milestone M1 and beyond. **No executable test code is implemented in milestone M0.**

---

## 1. Test Pyramid & Methodology

```
             / \
            /   \
           / E2E \       --> Multi-container Docker (Subdomain/Subdirectory)
          /-------\
         / Integr- \     --> Real WordPress Multisite & Standalone DB Fixtures
        /   ation   \
       /-------------\
      /     Unit      \  --> Fast, isolated, pure logic (prefix, regex, serial)
     /-----------------\
```

### 1.1 Strict TDD Execution Discipline
Starting in milestone M1, all executable code follows the Red/Green/Refactor cycle:
1. **Red**: Write a failing test asserting desired behavior.
2. **Green**: Write the minimal code required to pass the test.
3. **Refactor**: Clean code and optimize performance while maintaining green tests.
4. **Regression**: Run the full suite across all supported PHP and WordPress versions.

---

## 2. Test Environments & Multisite Fixtures

To thoroughly validate all four migration directions, the test harness requires three distinct WordPress fixture topologies:

### Fixture Environment A: Source Multisite Network (Subdomain)
- Domain: `network-a.local`
- Routing: Subdomain (`site1.network-a.local`, `site2.network-a.local`)
- Sites:
  - Site ID 1 (Main Site): Standard root prefix `wp_`, custom posts, network options.
  - Site ID 2 (Subsite): Prefix `wp_2_`, 50 posts, 20 media attachments, custom post type.
  - Site ID 3 (Large Subsite): Prefix `wp_3_`, 500 posts, complex serialized metadata.
- Assets:
  - Parent theme (`twentytwentyfour`) and active child theme on Site 2.
  - Network-activated plugin (`siteconvoy-test-sitewide`) and per-site plugin.
  - Must-use plugin (`mu-plugins/security-check.php`).
- Users:
  - User 1: Super Admin (`admin_a@network-a.local`)
  - User 2: Author on Site 2 only (`author_site2@network-a.local`)
  - User 3: Editor on Site 1 & Site 3 (`editor_shared@network-a.local`)

### Fixture Environment B: Target Multisite Network (Subdirectory)
- Domain: `network-b.local`
- Routing: Subdirectory (`network-b.local/sub1/`, `network-b.local/sub2/`)
- Existing Sites: Site ID 1, Site ID 2 (`/sub1/`).
- Users: Contains user with email `author_site2@network-a.local` to trigger explicit collision handling.

### Fixture Environment C: Standalone WordPress Installation
- Domain: `standalone.local`
- Standard prefix: `wp_`
- Clean standalone single site with distinct posts and media uploads under `wp-content/uploads/`.

---

## 3. End-to-End Migration Test Scenarios

### Scenario 1: Subsite to Standalone (Direction 1)
- **Input**: Site ID 2 from Network A.
- **Verification Gates**:
  - All posts and attachments extracted.
  - Table prefix normalized to `wp_`.
  - Media path rewritten from `uploads/sites/2/` to `uploads/`.
  - Content URLs updated to `standalone.local`.
  - `wp_posts.guid` entries match source verbatim.
  - **Role Preservation & Bootstrap Admin**: User 2 is transferred preserving their original role (**Author**); User 2 is **NOT** automatically elevated to Administrator. A destination-side bootstrap action (or explicit operator configuration) establishes the target administrator. User 1 and User 3 are excluded.

### Scenario 2: Main Site (Site ID 1) to Standalone (Direction 1 Special Case)
- **Input**: Site ID 1 from Network A.
- **Verification Gates**:
  - `wp_posts`, `wp_options`, `wp_terms` transferred.
  - `wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_signups`, `wp_registration_log`, `wp_blog_versions` strictly omitted.
  - Network-only options stripped from `wp_options`.
  - Target functions as a pure single-site WordPress install.

### Scenario 3: Subsite to Target Multisite Network (Direction 2)
- **Input**: Site ID 2 from Network A imported into Network B.
- **Verification Gates**:
  - Target allocates Site ID 3 (`network-b.local/sub3/`).
  - Table prefix rewritten to `wp_3_`.
  - Media relocated to `uploads/sites/3/`.
  - Colliding user detected: Admin approval simulated. Roles assigned without privilege escalation.

### Scenario 4: Full Network Migration (Direction 3)
- **Input**: Complete Network A migrated to new domain `network-c.local`.
- **Verification Gates**:
  - All site IDs and tables preserved (including all global, `ms_global`, and site tables).
  - Global `wp_site`, `wp_blogs`, and `wp_sitemeta` updated.
  - URLs updated across all subsites.
  - All post GUIDs preserved.
  - Super admin access preserved on new domain.
  - **Asset Completeness Verification**:
    - Network-activated plugins are restored and marked sitewide active in target `sitemeta`.
    - Both child theme and its required parent theme are provisioned in `wp-content/themes/`.
    - Must-use plugins in `wp-content/mu-plugins/` are transferred.
    - Missing drop-in dependencies or incompatible host drivers trigger clear preflight warnings.

### Scenario 5: Standalone to Multisite Subsite (Direction 4)
- **Input**: Standalone WordPress imported into Network B.
- **Verification Gates**:
  - Tables converted to `wp_{new_id}_*`.
  - Media relocated to `uploads/sites/{new_id}/`.
  - **Explicit Approval**: Standalone admin mapped to a subsite-level role on Network B **only** upon explicit approval by Network B Super Administrator. Standalone admin is strictly barred from receiving network super-admin rights.

### Scenario 6: Offloaded Cloud Media Missing Verification
- **Input**: Standalone or subsite with media offloaded to external S3/GCS where local files are absent.
- **Verification Gates**:
  - Preflight detects external object storage provider.
  - If offloaded object sync is unconfigured, migration is **BLOCKED / FLAGGED INCOMPLETE** with clear diagnostic warning.
  - Zero silent data loss or empty media references on target.

### Scenario 7: Live Source Writes & Write Freeze Verification
- **Input**: Source site subjected to continuous synthetic REST API write requests and simulated background cron jobs during export.
- **Verification Gates**:
  - Migration write freeze is activated: REST API write endpoints return HTTP 503; WP-Cron spawning is disabled.
  - Preflight warns if reliable write freeze cannot be established.
  - If background writes occur, export manifest logs live-write drift warning, triggering operator review gate on target.

---

## 4. Fault Injection & Rollback Testing

To ensure the reliability design goals are verified without making false assumptions regarding atomic OOM or DDL:

| Fault Injected | Stage | Realistic Behavior & Recovery Protocol |
|---|---|---|
| **Simulated Disk Full** | Mid-archive extraction | Extraction aborted; staging directory purged; destination database unmutated. |
| **SQL Syntax / Table Collision** | Mid-database import | Transaction rolled back for DML (InnoDB); for DDL statements (where MySQL executes implicit commits), compensating recovery drops newly staged tables using journal ledger. |
| **PHP Fatal Out-of-Memory (OOM)** | Batch serialized replace | Process killed immediately (cannot run cleanup handlers). **Recovery**: Checkpoints are persisted *prior* to batch execution. Upon process restart, the runner inspects durable disk journal and resumes from last safe checkpoint. |
| **Corrupted SHA-256 Checksum** | Package verification | Extraction aborted before any files or database tables are modified. |
| **Untrusted / Replaced Archive** | Trust review | Valid internal checksum but unconfirmed out-of-band digest requires explicit admin trust approval before staging. |
| **Missing Asset Dependency** | Asset provisioning | Missing parent theme or incompatible drop-in transitions to `BLOCKED_DEPENDENCY` before live database or filesystem mutation. |
