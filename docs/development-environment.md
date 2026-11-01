# Local Development & Test Environment Guide

> [!IMPORTANT]
> **Pre-release / Testing Harness Only**
> SiteConvoy is currently at Milestone M1 (`SITECONVOY-M1-001`). The plugin bootstrap (`siteconvoy.php`) is intentionally inert and provides **no migration runtime functionality**. All environments and fixtures described here use synthetic test data only and must never be pointed at production databases or live websites.

---

## 1. Prerequisites

To run the local development, linting, and integration test suite, install the following tools:

- **Docker Desktop** (or Docker Engine + Docker Compose v2) — running and accessible from your terminal.
- **Node.js** (LTS `>=20.x`) and **npm** (`>=10.x`) — required for [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/).
- **PHP CLI** (`>=8.1` recommended locally; `composer.json` locks platform resolution to PHP `8.2.10` for container parity) and **Composer** (`2.x`).
- **Git** (`2.x`).

All commands below work identically in **Windows PowerShell** and **POSIX (Linux / macOS / WSL2 bash)** shells unless explicitly noted.

---

## 2. Clean Checkout Installation

From the root of the public `siteconvoy` repository:

```powershell
# 1. Install Composer dev dependencies (PHPUnit, Yoast Polyfills, WPCS/PHPCS, Parallel-Lint)
composer install

# 2. Install Node dev dependencies (@wordpress/env)
npm install
```

Verify configuration integrity:

```powershell
composer validate --strict
```

---

## 3. Starting the Local WordPress Environments (`@wordpress/env`)

SiteConvoy uses the official WordPress [`@wordpress/env`](https://developer.wordpress.org/block-editor/getting-started/devenv/get-started-with-wp-env/) Docker environment manager.

### 3.1 Default Environment Configuration (`.wp-env.json`)

The primary [`.wp-env.json`](../.wp-env.json) provisions two isolated WordPress + MariaDB container pairs:

| Container Role | Port | Default Mode | Purpose |
|---|---|---|---|
| **Development (`cli` / `wordpress`)** | `http://localhost:9080` | **Multisite (Subdirectory)** (`"multisite": true`) | Interactive multisite network (`Site ID 1` at `/` and `Site ID 2` at `/subsite/`). |
| **Tests (`tests-cli` / `tests-wordpress`)** | `http://localhost:9081` | **Standalone + PHPUnit Test Runner** (`"multisite": false`) | Runs both standalone (`phpunit.xml.dist`) and multisite (`phpunit-multisite.xml.dist`) PHPUnit integration suites against an isolated test database (`tests-wordpress`). |

> [!NOTE]
> **Why `env.tests.multisite` is set to `false` in `.wp-env.json`:**
> When `@wordpress/env` sets `"multisite": true` on the `tests` environment, it injects `define( 'MULTISITE', true );` into `/wordpress-phpunit/wp-tests-config.php` *before* WordPress core's `tests/phpunit/includes/install.php` runs `populate_network()`. In WordPress core (`wp-admin/includes/schema.php`), `populate_network()` skips inserting `blog_id = 1` into `wp_blogs` if `is_multisite()` already returns `true`. Keeping `env.tests.multisite` as `false` allows `phpunit.xml.dist` to run single-site integration tests and `phpunit-multisite.xml.dist` (which defines `WP_TESTS_MULTISITE = 1`) to install and test a complete multisite network cleanly.

Start the environment:

```powershell
npm run env:start
# Or directly:
npx wp-env start
```

### 3.2 Standalone Development Configuration (`.wp-env.standalone.json`)

If you also want a dedicated standalone (single-site) development instance on separate ports (`9082` / `9083`), use [`.wp-env.standalone.json`](../.wp-env.standalone.json):

```powershell
npm run env:start:standalone
# Or directly:
npx wp-env start --config=.wp-env.standalone.json
```

---

## 4. Provisioning Deterministic Synthetic Fixtures

Once `wp-env` is running, seed the deterministic synthetic fixtures (Main Site ID 1, Subsite ID 2 at `/subsite/`, synthetic users `siteconvoy_author_site2` and `siteconvoy_editor_shared`, and synthetic posts) using [`bin/setup-fixtures.php`](../bin/setup-fixtures.php).

`bin/setup-fixtures.php` enforces **fail-closed safety guards** before mutating any database state:
1. Must be executed via WP-CLI (`wp eval-file`).
2. Requires explicit opt-in via the `allow-test-fixtures` positional argument (or `SITECONVOY_ALLOW_TEST_FIXTURES=1`).
3. Requires `wp_get_environment_type()` to be one of `local`, `development`, or `test` (refuses `production` and `staging`).
4. Requires `home_url()` and `site_url()` hostnames to be `localhost`, `127.0.0.1`, or RFC-reserved test domains (`*.local`, `*.test`, `example.org`, etc.).

```powershell
# Provision Multisite (Subdirectory) fixtures on the development container (port 9080):
npm run env:fixtures:multisite
# Equivalent raw command:
# npx wp-env run cli wp eval-file wp-content/plugins/siteconvoy/bin/setup-fixtures.php allow-test-fixtures

# Provision Standalone fixtures on the test container (port 9081):
npm run env:fixtures:standalone
# Equivalent raw command:
# npx wp-env run tests-cli wp eval-file wp-content/plugins/siteconvoy/bin/setup-fixtures.php allow-test-fixtures
```

Verify the provisioned sites and plugin status via WP-CLI:

```powershell
npx wp-env run cli wp site list
npx wp-env run cli wp plugin list
npx wp-env run tests-cli wp plugin list
```

> [!NOTE]
> **Synthetic Credentials Only:**
> Standard `@wordpress/env` local credentials are `admin` / `password` on `localhost`. Synthetic test users created by `bin/setup-fixtures.php` use `.test` reserved domains (`@siteconvoy-fixture.test`) and non-production placeholder passwords.

---

## 5. Running Linting, Coding Standards, and Automated Tests

### 5.1 PHP Syntax Lint & WordPress Coding Standards (WPCS)

Run locally on the host (requires PHP CLI + Composer):

```powershell
# PHP parallel syntax check across all repository PHP files
composer run lint:php

# WordPress Coding Standards (WordPress-Core, WordPress-Docs, WordPress-Extra, PrefixAllGlobals)
composer run lint:wpcs

# Automatically fix fixable coding standard violations
composer run lint:wpcs:fix
```

### 5.2 Fast Standalone Unit Tests (Host CLI)

Runs `tests/PluginUnitTest.php` via [`phpunit-unit.xml.dist`](../phpunit-unit.xml.dist) and [`tests/bootstrap-unit.php`](../tests/bootstrap-unit.php) without requiring a database connection:

```powershell
composer run test:unit
```

> [!NOTE]
> **Fail-Closed Integration Bootstrap (`tests/bootstrap.php`):**
> Running `vendor/bin/phpunit -c phpunit.xml.dist` or `phpunit-multisite.xml.dist` without a valid WordPress test suite (`WP_TESTS_DIR` or `/wordpress-phpunit`) fails closed immediately with a `RuntimeException` so missing WordPress test infrastructure is never misrepresented as passing integration tests.

### 5.3 Real WordPress PHPUnit Integration Tests (Docker `wp-env`)

Runs both `tests/PluginUnitTest.php` and `tests/PluginBootstrapTest.php` inside the `tests-cli` container against the real WordPress test library (`/wordpress-phpunit`) and MariaDB database:

```powershell
# Run Single-Site WordPress Integration Suite
npm run test:wp:single

# Run Multisite WordPress Integration Suite (WP_TESTS_MULTISITE=1)
npm run test:wp:multisite

# Run both Single-Site and Multisite Integration Suites sequentially
npm run test:wp
```

 Equivalent raw `wp-env` commands (Windows PowerShell & POSIX):

```powershell
npx wp-env run tests-cli --env-cwd=wp-content/plugins/siteconvoy vendor/bin/phpunit -c phpunit.xml.dist
npx wp-env run tests-cli --env-cwd=wp-content/plugins/siteconvoy vendor/bin/phpunit -c phpunit-multisite.xml.dist
```

---

## 6. Subdirectory vs. Subdomain Multisite Topology Coverage

### 6.1 What Is Verified Automatically

1. **Real Subdirectory Multisite Runtime & HTTP/WP-CLI (`wp-env`)**:
   - Fully provisioned and verified as a live subdirectory multisite network (`SUBDOMAIN_INSTALL = false`) on `http://localhost:9080/` (Site ID 1) and `http://localhost:9080/subsite/` (Site ID 2), as well as in the `phpunit-multisite.xml.dist` integration suite.
2. **Database-Only Subdomain Subsite Records (`phpunit-multisite.xml.dist`)**:
   - Inside the PHPUnit multisite integration suite (which runs with WordPress core's default `WP_TESTS_SUBDOMAIN_INSTALL = false`), `test_multisite_subdomain_and_subdirectory_subsite_addressing()` creates both subdirectory-addressed (`network-b.example.org/subdir-fixture/`) and subdomain-addressed (`site2.network-a.example.org/`) blog records in MariaDB via `WP_UnitTestCase::factory()->blog->create()`, verifying `wp_blogs` storage, `get_blog_details()`, dynamic `$wpdb->base_prefix`, and `$wpdb->get_blog_prefix( $site_id )`.
   - **Important Distinction**: This database-level blog record test does **not** constitute a full `SUBDOMAIN_INSTALL = true` HTTP/rewrite runtime test.

### 6.2 `wp-env` Localhost Subdomain Limitation & Actionable Subdomain Setup

**Limitation**: `@wordpress/env` binds its development container to `localhost:<port>` and runs `wp core multisite-convert` without `--subdomains`. Furthermore, WordPress core forbids `SUBDOMAIN_INSTALL` when `DOMAIN_CURRENT_SITE` is a bare single-label hostname like `localhost` (requiring a dotted domain such as `network-a.local`), and browsers/WP-CLI inside Docker do not automatically resolve arbitrary `*.localhost:<port>` or `*.network-a.local` subdomains without host/container `/etc/hosts` mappings.

**Actionable WP-CLI / Docker Procedure for Full Subdomain HTTP/CLI Provisioning**:

If you need to test HTTP/WP-CLI routing against an active `SUBDOMAIN_INSTALL` container environment:

> [!WARNING]
> **Destructive Database Reset Warning**
> The `wp db reset --yes` command below drops all tables in the active `wp-env` development container database (`wordpress`) and erases all existing local site content in that container. Never run `wp db reset` outside an isolated local disposable container.

1. Map `network-a.local`, `site1.network-a.local`, and `site2.network-a.local` to `127.0.0.1` in your host OS `hosts` file:
   - **Windows (Administrator PowerShell)**: `C:\Windows\System32\drivers\etc\hosts`
   - **Linux / macOS**: `/etc/hosts`
2. Convert or install the containerized network with `--subdomains` via WP-CLI:
   ```powershell
   npx wp-env run cli wp db reset --yes
   npx wp-env run cli wp core multisite-install --url="http://network-a.local:9080" --subdomains --title="SiteConvoy Subdomain Network" --admin_user="admin" --admin_password="password" --admin_email="admin@siteconvoy-fixture.test" --skip-email
   npx wp-env run cli wp site create --slug="site2" --title="Subdomain Site 2" --email="admin@siteconvoy-fixture.test"
   ```

---

## 7. Resetting Fixtures, Stopping, and Teardown

### 7.1 Resetting Database State Safely

> [!WARNING]
> **Destructive Database Wipe Warning**
> Running `npm run env:clean` (`npx wp-env clean all`) permanently wipes and re-initializes the local Docker databases for both the development and test containers. Only run this command on local disposable `wp-env` test environments.

To wipe and re-initialize only the SiteConvoy Docker databases without deleting downloaded WordPress core images:

```powershell
npm run env:clean
# Or directly:
npx wp-env clean all
```

After cleaning, re-seed synthetic fixtures with `npm run env:fixtures:multisite` and `npm run env:fixtures:standalone`.

### 7.2 Stopping Containers

To stop running containers while preserving volumes:

```powershell
npm run env:stop
# Or directly:
npx wp-env stop
```

### 7.3 Warning on Destructive Teardown (`wp-env destroy`)

> [!CAUTION]
> **Destructive Operation Warning**
> Running `npx wp-env destroy` permanently deletes all Docker containers, MariaDB volumes, local uploads, and cached WordPress core files associated with this project directory. Only run `npx wp-env destroy` when you intentionally want to remove the entire local Docker environment from your machine.

---

## 8. Authoritative References

- [`@wordpress/env` Package Reference](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
- [Get started with `wp-env`](https://developer.wordpress.org/block-editor/getting-started/devenv/get-started-with-wp-env/)
- [WordPress Core Automated Testing (PHPUnit)](https://make.wordpress.org/core/handbook/testing/automated-testing/phpunit/)
- [WP-CLI `wp core multisite-install`](https://developer.wordpress.org/cli/commands/core/multisite-install/)
