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

Paths must contain at least two segments (e.g. `root/filename`); bare root names without payload filenames are prohibited.

### Prohibited & Reserved Paths
- **Reserved Metadata**: `manifest.json` and `checksums.sha256` are package governance files and may not appear as regular payload entries. Future readers may consume `checksums.sha256` as an optional, redundant standard listing (e.g., for standard command-line tools like `sha256sum -c checksums.sha256`), while `manifest.json` remains the authoritative contract for archive structure and verification.
- **Prohibited Sensitive Files**: `wp-config.php`, `.env`, `.htaccess`, and `web.config` are strictly rejected.
- **Windows Device Names & Trailing Aliases**: Reserved device names (`CON`, `PRN`, `AUX`, `NUL`, `COM1-9`, `LPT1-9`) with or without arbitrary extensions, trailing spaces/dots in path segments (e.g. `foo. `), and Alternate Data Stream colon syntax (e.g. `file.txt:stream`) are rejected across all platforms to prevent cross-platform extraction vulnerabilities.
- **Traversal & Absolute Paths**: Slashes at start (`/`), Windows backslashes (`\`), drive letters (`C:`), UNC paths (`//`), null bytes (`\0`), and path traversal segments (`.` or `..`) are rejected immediately.
- **Unicode, Canonical Normalization, and Casefold Ambiguities**:
  - **ASCII Compatibility Invariant**: Standard ASCII paths (`[\x20-\x7E]`) are fully supported across all environments and configurations with zero PHP extension dependencies.
  - **Extension-Independent Fail-Closed Policy**: Non-ASCII (international) paths require both the PHP `intl` extension (for canonical normalization) and the PHP `mbstring` extension (for multibyte case folding). If either extension is unavailable on the runtime host, non-ASCII paths fail closed with a stable, sanitized validation error. This prevents silent acceptance of aliasing paths or inconsistent normalization on hosts lacking multibyte facilities.
  - **Unicode Form & Canonical Normalization (NFC Policy)**: When `intl` is present, `Normalizer::isNormalized($path, Normalizer::FORM_C)` strictly enforces precomposed characters (e.g. `café.png`, Hangul `가`). Decomposed combining mark sequences (Unicode category `\p{M}`, e.g. `e` + `\u0301`) and decomposed Hangul Jamo syllables fail closed. Pure PHP environments lacking `intl` reject all non-ASCII paths rather than risk incomplete canonical checks.
  - **Case-Collision Prevention (Case Folding)**: When `mbstring` is present, case folding is performed using `mb_convert_case($path, MB_CASE_FOLD, 'UTF-8')` (falling back to `mb_strtolower($path, 'UTF-8')`). This catches full Unicode case-fold collisions (e.g., German eszett `straße.png` vs `STRASSE.PNG`, and umlauts `uploads/Ü.png` vs `uploads/ü.png`) in addition to standard ASCII collisions (`photo.jpg` vs `PHOTO.JPG`).
  - **Limitations & Out-of-Scope Guarantees**: This policy prevents canonical NFC/NFD duplicates and Unicode case-fold collisions within a package. It does not perform NFKC/NFKD compatibility clustering, script-mixing/homoglyph spoof detection (e.g. Cyrillic `а` vs Latin `a`), or locale-tailored casing transformations (e.g. Turkish dotted `İ`/`i`).
- **Entry Types**: Only `regular` files are allowed; non-regular files (`symlink`, `hardlink`, `directory`, `device`) and unrecognized metadata keys fail closed.

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
Entries in `PackageManifest` are sorted canonically by `path` using standard byte-order comparison (`strcmp`). Duplicate paths and case-colliding variants (e.g. `uploads/photo.jpg` vs `uploads/PHOTO.JPG`, or `uploads/Ü.png` vs `uploads/ü.png`) are rejected upon ingestion to ensure deterministic cross-platform portability.

### Source Table Prefix & Sanitization Invariants
- `source.base_prefix` must adhere to WordPress table-prefix syntax (alphanumeric and underscores `/^[a-zA-Z0-9_]+$/`). Arbitrary paths, URLs, secrets, or control characters are rejected immediately.
- Manifest exceptions and verification results sanitize all external input; arbitrary passwords, private paths, or unexpected keys are never reflected in error messages or exception strings.

---

## 4. Streaming SHA-256 Verifier (`PackageStreamVerifier`)

`SiteConvoy\Package\PackageStreamVerifier` performs incremental, bounded-memory verification of supplied streams against a `PackageManifest`:
- **Incremental Chunking**: Reads streams in bounded 64 KB chunks (`hash_init`, `hash_update`, `hash_final`) without buffering whole files into PHP memory.
- **Stream Wrapper Allowlist**: Enforces a strict allowlist of readable local streams (`plainfile / STDIO`, `php://temp`, `php://memory`). Rejects write-only streams (`UNREADABLE_STREAM`), nonlocal wrappers (e.g. `data://`, `http://`, `ftp://`), and filter wrappers (`php://filter`) with `DISALLOWED_STREAM_WRAPPER`. Never exposes underlying resource URIs in public results.
- **Fail-Closed Verification**:
  - Truncated streams (fewer bytes than declared) fail with `SIZE_MISMATCH`.
  - Overlong streams (more bytes than declared) fail fast with `SIZE_MISMATCH`.
  - Hash corruption fails with `CHECKSUM_MISMATCH`.
  - Missing streams fail with `STREAM_MISSING`.
  - Unexpected streams in map fail with `UNEXPECTED_STREAM` (sanitizing unexpected keys without echoing sensitive caller strings).
  - Stalled reads before EOF fail closed with `STREAM_READ_STALLED`.
  - Duplicate stream handles delivered across distinct entry keys fail with `DUPLICATE_STREAM_RESOURCE`.
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
