# SiteConvoy Migration Package Format Specification

> [!NOTE]
> **Task Reference:** `SITECONVOY-M2-001`  
> **Status:** SPECIFICATION & READ-ONLY INTEGRITY VERIFIER (`format_version: "1.0"`)  
> This document specifies SiteConvoy's migration package structure, canonical `manifest.json` contract, safe entry path validation rules, and streaming SHA-256 integrity verification.

---

## 1. Architectural Philosophy: Integrity vs. Authenticity

> [!CAUTION]
> **INTEGRITY IS NOT AUTHENTICITY**  
> A valid SHA-256 checksum proves only that a byte stream has not been corrupted, truncated, or modified relative to the manifest that declared it. **It does not prove that the package or manifest originated from an authorized source.** An attacker who intercepts or replaces an entire archive package can trivially generate an updated `manifest.json` containing valid SHA-256 hashes for malicious payloads.
> 
> Checksum verification inside `PackageStreamVerifier` is an **internal integrity guard** preventing partial writes, storage corruption, and truncation. **Out-of-band trust**, cryptographic signatures, or privileged administrator verification are downstream acceptance gates and are never implied by internal checksum validation.

---

## 2. Package Directory Layout & Safe Roots

A SiteConvoy migration package contains regular file payloads organized under strict approved root directories:

```
package.zip (future container)
├── manifest.json            <-- Package metadata, mode, topology, and entry hashes
├── checksums.sha256         <-- Redundant standard sha256sum listing (future)
├── database/                <-- Isolated table SQL dumps
│   ├── wp_posts.sql
│   └── wp_postmeta.sql
├── uploads/                 <-- Media library files
│   └── 2026/01/photo.jpg
└── assets/                  <-- Theme/plugin/mu-plugin metadata/snapshots
    └── themes/twentytwentyfour.json
```

### Approved Root Prefixes
Only three root directory prefixes are permitted for entry paths:
1. `database/` &mdash; Serialized SQL table data and schema statements.
2. `uploads/` &mdash; Media and uploaded content files.
3. `assets/` &mdash; Metadata and plugin/theme asset definitions.

### Prohibited & Reserved Paths
- **Reserved Metadata**: `manifest.json` and `checksums.sha256` are package governance files and may not appear as regular payload entries.
- **Prohibited Sensitive Files**: `wp-config.php`, `.env`, `.htaccess`, and `web.config` are strictly rejected.
- **Windows Device Names**: Reserved device names (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`) are rejected across all platforms to prevent cross-platform extraction vulnerabilities.
- **Traversal & Absolute Paths**: Slashes at start (`/`), Windows backslashes (`\`), drive letters (`C:`), UNC paths (`//`), null bytes (`\0`), and path traversal segments (`.` or `..`) are rejected immediately.

---

## 3. Canonical Package Manifest Schema (`manifest.json`)

The canonical schema is defined in [`schemas/package-manifest.json`](../schemas/package-manifest.json).

```json
{
  "format_version": "1.0",
  "mode": "subsite_to_standalone",
  "source": {
    "is_multisite": true,
    "network_id": 1,
    "site_id": 2,
    "base_prefix": "wp_"
  },
  "entries": [
    {
      "path": "database/wp_2_posts.sql",
      "size": 1048576,
      "sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    }
  ]
}
```

### Deterministic Entry Ordering
Entries in `PackageManifest` are sorted canonically by `path` using standard byte-order comparison (`strcmp`). Duplicate paths and case-colliding variants (e.g. `uploads/photo.jpg` vs `uploads/PHOTO.JPG`) are rejected upon ingestion to ensure deterministic cross-platform portability.

---

## 4. Streaming SHA-256 Verifier (`PackageStreamVerifier`)

`SiteConvoy\Package\PackageStreamVerifier` performs incremental, bounded-memory verification of supplied streams against a `PackageManifest`:
- **Incremental Chunking**: Reads streams in bounded 64 KB chunks (`hash_init`, `hash_update`, `hash_final`) without buffering whole files into PHP memory.
- **Fail-Closed Verification**:
  - Truncated streams (fewer bytes than declared) fail with `SIZE_MISMATCH`.
  - Overlong streams (more bytes than declared) fail fast with `SIZE_MISMATCH`.
  - Hash corruption fails with `CHECKSUM_MISMATCH`.
  - Missing streams fail with `STREAM_MISSING`.
  - Unexpected streams in map fail with `UNEXPECTED_STREAM`.
  - Read errors fail with `STREAM_READ_ERROR`.
- **Resource Management**: Automatically closes stream resources upon completion by default (`$close_streams = true`), preventing file handle exhaustion.

---

## 5. What Is NOT Built in M2-001

The following capabilities are explicitly outside the scope of `SITECONVOY-M2-001`:
- **Archive Creation & Extraction**: No ZIP or TAR archives are created, opened, or extracted.
- **Data Export & Import**: No SQL queries, database rows, or option writes are executed.
- **Filesystem Mutations**: Production code performs zero file writes or staging directory creation.
- **URL Rewriting & Deserialization**: No search-replace or PHP object deserialization.
- **Execution & Orchestration**: The manifest and verifier are inert data structures; they do not trigger background jobs, REST endpoints, or migration cutover.
