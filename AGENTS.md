# Agent and Contributor Instructions — SiteConvoy

This document defines guidelines for both human contributors and AI coding agents (such as Codex, Antigravity, Claude, ChatGPT, etc.) working on the public `amitworx/siteconvoy` repository.

The public repository is the **single source of truth** for all product requirements, code, tests, CI pipelines, and public documentation. Public contributions must never depend on external private repositories or unshared proprietary infrastructure.

---

## Mandatory Engineering Standards

1. **Test-Driven Development (TDD)**:
   - For all executable code, write failing tests first, implement the minimum necessary code, and verify refactoring.
   - Separate unit tests (fast, isolated, mocked where appropriate) from true WordPress multisite integration tests.
2. **Never Fabricate Verification**:
   - Do not claim tests or lint checks passed if they were not executed.
   - Never classify documentation or static sanity checks as "integration tests".
   - Report actual commands executed, environment details, and verbatim outputs.
3. **WordPress Coding & Security Standards**:
   - Follow official WordPress Coding Standards (WPCS / PHPCS).
   - Enforce explicit permission checks (`current_user_can('manage_network')` or appropriate site capability), nonce verification, and strict input/output sanitization and escaping.
   - Use safe database operations (prepared statements via `$wpdb->prepare()`) and guard against SQL injection.
4. **Scope Discipline**:
   - Work strictly within the assigned task scope. Do not prematurely implement future milestones or bundle unrelated features into a single PR.
5. **Security and Secret Management**:
   - Never commit API keys, tokens, production database dumps, credentials, or private customer data.
   - Never force-push or rewrite public history on published branches without explicit maintainer authorization.
6. **Review Gates**:
   - All contributions must be submitted via pull requests and reviewed prior to merging. Direct commits to `main` are strictly prohibited.
