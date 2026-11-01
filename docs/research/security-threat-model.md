# SiteConvoy Security & Threat Model

> [!NOTE]
> **Task Reference:** `SITECONVOY-M0-002`  
> **Status:** PROPOSED SECURITY SPECIFICATION  
> Comprehensive threat model, trust boundaries, privilege matrices, archive verification standards, and defensive controls for SiteConvoy.

---

## 1. Trust Boundaries and User Privilege Profiles

Multisite operations involve fundamentally distinct security profiles compared to standalone WordPress installations:

```
[Untrusted Network / Web]
         |
         v
+---------------------------------------------------------------------------------+
| Trust Boundary A: Web Request / API Verification                                |
| - Nonce Verification & Rate Limiting                                            |
| - Capability Checks: `manage_network` (Multisite) vs `manage_options` (Standalone)|
+---------------------------------------------------------------------------------+
         |
         v
+---------------------------------------------------------------------------------+
| Trust Boundary B: Archive Integrity & Sanitization                              |
| - Cryptographic SHA-256 Checksum Verification                                   |
| - Path Traversal & Archive Bomb Inspection                                      |
| - SQL Sanitization & Strict Table Boundary Parsing                              |
+---------------------------------------------------------------------------------+
         |
         v
+---------------------------------------------------------------------------------+
| Trust Boundary C: Local Storage & Execution State                               |
| - Staging Directory Isolation (.htaccess / index.php deny)                      |
| - Ephemeral Secret Tokens & Non-Exportable Salt Keys                            |
+---------------------------------------------------------------------------------+
```

### 1.1 Privilege Hierarchy

- **Network Super-Administrator (`manage_network`)**:
  - Highest privilege in WordPress.
  - Authorized to initiate, configure, and restore migrations across the network, allocate subsites, and review cross-network user mappings.
- **Site Administrator (`manage_options`)**:
  - Authorized on standalone installations.
  - On multisite networks, individual site administrators **CANNOT** create new network subsites, access global tables (`wp_users`, `wp_sitemeta`), or execute cross-network imports without explicit super-admin delegation.
- **Imported Super-Administrator Guard**:
  - **Fundamental Security Rule**: SiteConvoy will **NEVER** grant `manage_network` capabilities or elevate user roles to Network Super Administrator as a consequence of importing a subsite archive.

---

## 2. Threat Analysis & Defensive Mitigations

| Threat | Risk / Impact | Attack Vector | Defensive Controls |
|---|---|---|---|
| **T1: Insecure Account Hijacking via Email Collision** | **CRITICAL** | Attacker crafts a migration archive with a user record matching a target administrator's email, tricking the importer into linking credentials. | **Prohibit Silent Auto-Linking**. All email/login matches trigger preflight flags. Explicit target admin review and authorization required. |
| **T2: Malicious Archive Path Traversal (Zip Slip)** | **HIGH** | Archive contains relative path filenames (`../../wp-config.php`) to overwrite sensitive core files. | Canonical path resolution (`realpath()`), strict sandbox validation ensuring all extracted files reside inside the isolated staging directory. |
| **T3: PHP Object Injection via Deserialization** | **HIGH** | Malicious serialized payload in postmeta/options triggers arbitrary code execution upon `unserialize()`. | Use safe recursive JSON or dedicated tokenized parsers; avoid native `unserialize()` on untrusted input without allowed class filters (`['allowed_classes' => false]`). |
| **T4: SQL Injection in Table DDL / DML** | **HIGH** | Specially crafted table names or column values inject raw SQL during restoration. | Strict schema validation; sanitize table names via whitelist regex (`/^[a-zA-Z0-9_]+$/`); parameterize all SQL execution. |
| **T5: Server-Side Request Forgery (SSRF)** | **MEDIUM** | Attacker specifies internal cloud metadata IP (e.g., `169.254.169.254`) as remote migration source. | Validate destination/source URLs using `wp_http_validate_url()`, rejecting private/reserved IP ranges and non-HTTP(S) protocols. |
| **T6: Unauthorized Archive Access / Information Leak** | **HIGH** | Unauthenticated user downloads generated migration archive from publicly accessible `wp-content/uploads/` path. | Store archives outside the webroot, or in an isolated directory protected with server directives (`Deny from all`) and random cryptographic non-guessable hash directory paths. |
| **T7: Replay & Race Conditions** | **MEDIUM** | Intercepted migration token re-used to trigger repeated restoration or overwrite. | Single-use HMAC migration tokens with short expiration windows; strict state-machine transition locking. |

---

## 3. Data Privacy and Confidentiality Rules

1. **Synthetic Data Only**: All automated test fixtures, documentation examples, and architectural diagrams must use synthetic domains (`example.com`, `network.local`) and dummy user credentials.
2. **Exclusion of Sensitive Secrets**:
   - Database passwords, auth salts (`AUTH_KEY`, `SECURE_AUTH_KEY`, etc.), and private server keys must be omitted from migration packages.
   - Target installations must preserve their own unique salt keys and database credentials.
3. **Data Minimization on User Export**:
   - Multisite exports must include only users with explicit roles or authorship ties to the specific migrating site. Unrelated network accounts are completely excluded.

---

## 4. Testable Security Acceptance Criteria

- [ ] **SEC-01**: Attempting to extract a package with invalid nonces or non-super-admin user must return HTTP 403 Forbidden.
- [ ] **SEC-02**: An archive containing directory traversal filenames (`../evil.php`) must be rejected during preflight extraction inspection without touching disk.
- [ ] **SEC-03**: Subsite import with colliding super-admin email must flag collision and prohibit privilege elevation.
- [ ] **SEC-04**: Direct URL access to archive storage directory must return HTTP 403 Forbidden.
- [ ] **SEC-05**: Migration archive with modified SHA-256 checksum must fail preflight verification and halt import.
