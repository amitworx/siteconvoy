# SiteConvoy Roadmap

This roadmap outlines the phased development path leading to the version 1.0 release of SiteConvoy.

> [!NOTE]
> SiteConvoy prioritizes rigorous architectural correctness, safety, and reproducible multisite verification over arbitrary release dates. Delivery timelines are determined by milestone quality gates rather than speculative schedules.

---

## Phase Overview

### Milestone M0: Public Foundation and Contributor Governance *(Current)*
- Establish public open-source repository governance, licensing (GPL-2.0-or-later), and contributor guidelines.
- Lay down core architecture, migration matrices, and safety specifications.
- **Deliverables**: Documentation and governance only; zero runtime plugin code.
- **Exit Gate**: Public governance approved, branch integrity verified, clean automated diff checks.

### Milestone M1: Test Harness and Environment Infrastructure *(Planned)*
- Configure PHP coding standards (PHPCS / WPCS) and static analysis tools.
- Establish a reproducible WordPress Multisite testing harness (supporting both subdomain and subdirectory network configurations).
- Define synthetic database fixtures and sanitized migration test assets.
- **Exit Gate**: Passing CI pipeline running multisite fixtures against supported PHP and WordPress versions.

### Milestone M2: Core Migration Engine & Direction 1 *(Planned)*
- Implement core archive packaging, streaming decompression, cryptographic checksum verification, and staged extraction.
- Implement **Multisite Subsite &rarr; Standalone WordPress**:
  - Subsite table extraction and prefix normalization (`wp_{blog_id}_*` to `wp_*`).
  - Dedicated handling for main site (Site ID 1) extraction and table deduplication.
  - User filtering and remapping for site-specific roles.
  - Media path translation (`wp-content/uploads/sites/{id}/` to `wp-content/uploads/`).
  - Serialized data search-and-replace for domain and path adjustments (preserving `wp_posts.guid`).
- **Exit Gate**: End-to-end automated integration tests for subsite and main-site standalone conversions.

### Milestone M3: Direction 2 — Multisite Subsite to Another Multisite *(Planned)*
- Implement **Multisite Subsite &rarr; Target Multisite Network**:
  - Target site ID assignment and table prefix rewriting.
  - Cross-network identity conflict detection; cautious administrator review required for matching accounts (no silent auto-linking; no imported super-admin privileges).
  - Target media reorganization into `wp-content/uploads/sites/{new_id}/`.
  - Network-active plugin conflict detection and option merging.
- **Exit Gate**: Full integration test coverage for subsite transfer between distinct networks.

### Milestone M4: Direction 3 — Full Multisite Network Migration *(Planned)*
- Implement **Entire Multisite Network &rarr; New Environment / Server**:
  - Full network database capture (global tables `wp_blogs`, `wp_site`, `wp_sitemeta`, `wp_users`, `wp_usermeta` + all site tables).
  - Global domain and path replacement across all network sites and sitemeta (leaving `wp_posts.guid` unmodified).
  - Complete media archive preservation and filesystem layout restoration.
- **Exit Gate**: Full network restoration verified across different host environments and domain names.

### Milestone M5: Direction 4 — Standalone WordPress to Multisite Subsite *(Planned)*
- Implement **Standalone WordPress &rarr; Multisite Subsite**:
  - Standalone table re-prefixing to target subsite prefix (`wp_*` to `wp_{new_id}_*`).
  - User integration into global network users table with collision detection and explicit administrator review.
  - Media path migration to target network subsite uploads folder.
  - Subdomain and subdirectory routing configuration.
- **Exit Gate**: Standalone site successfully imported into an existing multisite network.

### Milestone M6: Security Hardening, Preflight Checks & Recovery *(Planned)*
- Finalize comprehensive preflight dry-run validator (disk space, execution limits, database privileges, table collisions).
- Validate backup restore points, staged filesystem execution, and best-effort compensating rollback mechanisms under injected failure conditions.
- Document manual recovery procedures and journal inspection for non-transactional database/filesystem interruptions.
- External security and architecture audit.
- **Exit Gate**: Zero critical security vulnerabilities; resilient failure recovery and clear diagnostics under injected runtime failures.

### Milestone M7: v1.0 Packaging & WordPress.org Release *(Planned)*
- User interface polish, WP-CLI command integration, and user documentation.
- Prepare WordPress.org plugin directory assets and final release tag.
- **Exit Gate**: Full v1.0 release supporting all 4 migration modes with complete test suites.
