# Contributing to SiteConvoy

Thank you for your interest in contributing to SiteConvoy! SiteConvoy is an open-source, GPL-2.0-or-later WordPress plugin dedicated to reliable WordPress multisite migrations.

The public repository (`amitworx/siteconvoy`) is the single source of truth for all requirements, documentation, code, tests, and CI configurations. No private repository access or internal resources are required to build, test, or contribute to this project.

---

## Development Principles

1. **Test-Driven Development (TDD)**:
   - For all executable code (beginning in milestone M1+), development must follow strict TDD:
     - Write a failing test first (Red).
     - Implement the minimum code necessary to make the test pass (Green).
     - Refactor while preserving passing tests (Refactor).
     - Verify against regressions across target PHP and WordPress environments.
2. **WordPress Standards**:
   - Code must adhere to WordPress Coding Standards (WPCS / PHPCS) and WordPress PHP documentation standards.
   - Use strict input sanitization, capability checks (`manage_network` where applicable), and nonce verification for all administrative actions.
3. **No Artificial Gating**:
   - All features must remain truly free and open source. Do not introduce commercial upsells, paywalls, or arbitrary data limits.
4. **Verifiable Quality**:
   - Never report unrun tests as passing. Document real commands, environments, and verifiable outputs.

---

## Local Setup

Detailed environment setup (such as Composer dependencies, Docker/wp-env multisite test harnesses, and PHPUnit configurations) is currently **TBD** as milestone M0 establishes project governance and documentation only.

When executable runtime development begins:
- A supported PHP CLI and Composer will be required for linting and unit testing.
- A local WordPress Multisite testing environment (subdomain and subdirectory configurations) will be provided for integration tests.

---

## Submitting Pull Requests

1. Fork the repository and create a descriptive branch (e.g., `feature/my-enhancement` or `fix/issue-description`).
2. Ensure changes follow the coding standards and documentation styles:
   - Run `git diff --check` to avoid whitespace errors.
   - Ensure markdown links are valid relative links.
3. Keep pull requests focused on a single concern or assigned task.
4. Submit pull requests as drafts if they represent work in progress or require architectural review.
5. Provide a clear description of changes, rationale, and reproducible testing evidence in the PR body.
