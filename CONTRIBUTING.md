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

## Local Setup & Verification

See the [Local Development & Test Environment Guide](docs/development-environment.md) for full setup, Windows PowerShell and POSIX commands, synthetic fixture provisioning, and topology notes.

Quick-start summary:

```powershell
# Install PHP and Node dev dependencies
composer install
npm install

# Run PHP syntax check, WordPress Coding Standards (WPCS), and fast unit tests
composer check

# Start the local Docker WordPress environments (@wordpress/env)
npm run env:start

# Seed deterministic synthetic fixtures (Multisite Site 1 + Site 2, and Standalone)
npm run env:fixtures:multisite
npm run env:fixtures:standalone

# Run real WordPress PHPUnit integration tests (Single-Site + Multisite)
npm run test:wp
```

---

## Submitting Pull Requests

1. Fork the repository and create a descriptive branch (e.g., `feature/my-enhancement` or `fix/issue-description`).
2. Follow strict TDD (write failing test first, implement minimal code, refactor).
3. Ensure all quality gates pass before opening or updating a pull request:
   - `git diff --check`
   - `composer validate --strict`
   - `composer run lint:php`
   - `composer run lint:wpcs`
   - `npm run test:wp`
4. Keep pull requests focused on a single concern or assigned task.
5. Submit pull requests as drafts if they represent work in progress or require architectural review.
6. Provide a clear description of changes, rationale, and reproducible testing evidence in the PR body.
