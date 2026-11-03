# SiteConvoy Migration Package Format Specification

> [!NOTE]
> **Task Reference:** `SITECONVOY-M2-001` / `SITECONVOY-M2-002`
>
> **Status:** SPECIFICATION, STREAM INTEGRITY VERIFIER & SAFE DETERMINISTIC ZIP WRITER (`format_version: "1.0"`)
> This document specifies SiteConvoy's migration package structure, canonical `manifest.json` contract, safe entry path validation rules, streaming SHA-256 integrity verification, and safe deterministic ZIP archive generation (`PackageArchiveWriter`).

---

## 1. Architectural Philosophy: Integrity vs. Authenticity

> [!CAUTION]
> **INTEGRITY IS NOT AUTHENTICITY**  
> A valid SHA-256 checksum proves only that a byte stream has not been corrupted, truncated, or modified relative to the manifest that declared it. **It does not prove that the package or manifest originated from an authorized source.** An attacker who intercepts or replaces an entire archive package can trivially generate an updated `manifest.json` containing valid SHA-256 hashes for malicious payloads.
> 
> Checksum verification inside `PackageStreamVerifier` and `PackageArchiveWriter` is an **internal integrity guard** preventing partial writes, storage corruption, and truncation. **Out-of-band trust**, cryptographic signatures, or privileged administrator verification are downstream acceptance gates and are never implied by internal checksum validation.

---

## 2. Package Directory Layout & Safe Roots

A SiteConvoy migration package contains regular file payloads organized under strict approved root directories:

```
package.zip
├── manifest.json            <-- Package metadata, mode, topology, and entry hashes (canonical byte order)
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
  - **No Capability Elevation**: Capability overrides in test seams can simulate absent capabilities (`false`), but can never force or elevate capabilities (`true`) when the underlying PHP extension is missing from the runtime environment.
  - **Unicode Form & Canonical Normalization (NFC Policy)**: When `intl` is present, `Normalizer::isNormalized($path, Normalizer::FORM_C)` strictly enforces precomposed characters (e.g. `café.png`, Hangul `가`). Decomposed combining mark sequences (Unicode category `\p{M}`, e.g. `e` + `\u0301`) and decomposed Hangul Jamo syllables fail closed. Pure PHP environments lacking `intl` reject all non-ASCII paths rather than risk incomplete canonical checks.
  - **Case-Collision Prevention (Case Folding)**: When `mbstring` is present, full case folding is performed using `mb_convert_case($path, MB_CASE_FOLD, 'UTF-8')` requiring both `mb_convert_case` and `MB_CASE_FOLD`. This catches full Unicode case-fold collisions (e.g., German eszett `straße.png` vs `STRASSE.PNG`, and umlauts `uploads/Ü.png` vs `uploads/ü.png`) in addition to standard ASCII collisions (`photo.jpg` vs `PHOTO.JPG`).
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

## 5. Safe Deterministic ZIP Package Writer (`PackageArchiveWriter`)

`SiteConvoy\Package\PackageArchiveWriter` implements SiteConvoy's first real, externally openable ZIP archive generation facility:

### Key Design & Safety Invariants
1. **Native ZIP / libzip Compatibility**:
   - Generates standard `.zip` files readable by any standard operating system or unzip utility (`unzip`, Windows Explorer, Archive Utility).
   - Checks for PHP `ZipArchive` extension capability up front (`ZIP_CAPABILITY_UNAVAILABLE`).
2. **Bounded-Memory Staging**:
   - Caller-supplied streams are read chunk-by-chunk (`64 KB`) and staged into individual private temporary files on disk. Payloads are never held in memory.
   - Streaming SHA-256 and byte size are computed and verified during staging. Any deviation triggers immediate cleanup and failure (`CHECKSUM_MISMATCH`, `SIZE_MISMATCH`).
3. **Deterministic Metadata**:
   - Members are added in deterministic sorted order (`manifest.json` first, followed by payload paths in byte-order).
   - Fixed epoch mtime (`1767225600`, representing `2026-01-01 00:00:00 UTC`) is assigned to every member via `setMtimeName()`.
   - Fixed compression method (`ZipArchive::CM_STORE`) is enforced via `setCompressionName()` to guarantee reproducible, bit-for-bit identical ZIP archives across platforms regardless of zlib compression variations.
   - Deterministic UNIX regular-file external attributes (`0100644 << 16` / `020151000000`) are explicitly normalized across all members via `setExternalAttributesName()`.
4. **Safe Staging, Restricted Permissions & Platform Containment**:
   - Requires an explicit, private temporary staging parent directory (`0700`).
   - The writer never recursively deletes caller workspaces. It allocates an exclusive, randomly named per-operation subdirectory (`0700`, `sc_op_<nonce>`).
   - **Supported Platform Policy (Fail-Closed on Windows)**:
     - **POSIX / Linux Hosts**: Fully supported. Restrictive file permissions (`0600`, owner read/write only) are explicitly enforced on temporary ZIP archives before publication and re-enforced at the published destination. Permissions are strictly verified via `fileperms()`, preventing data exposure under permissive process umasks.
     - **Windows Hosts**: Explicitly fail closed with structured error `SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE` before staging directories are created or payloads are written. Because Windows `chmod` controls only the read-only file attribute and cannot verify or enforce fine-grained NTFS Access Control Lists (ACLs), package writer operations are disabled on Windows environments until verified ACL enforcement is implemented.
   - Staging files use opaque random names (`stg_<nonce>.tmp`) &mdash; never derived from manifest relative paths &mdash; eliminating symlink-following and traversal risks within the staging workspace.
   - Symlinks for output destination, staging parent, and any of their ancestor directory components are strictly prohibited (`SYMLINK_PATH_PROHIBITED`).
5. **Independent Pre-Publication Verification**:
   - The archive is built in a temporary file (`sc_pkg_<nonce>.tmp`) inside the exclusive operation subdirectory.
   - Once closed, the finalized archive is reopened independently:
     - Member count must match `manifest.json` + declared entries exactly.
     - Members are enumerated: directory members (`/`), symbolic links (Unix attribute `0120000`), and non-regular file members are rejected (`ZIP_DIRECTORY_MEMBER_PROHIBITED`, `ZIP_SYMLINK_MEMBER_PROHIBITED`, `ZIP_NONREGULAR_MEMBER_PROHIBITED`). Missing or unreadable member external attributes fail closed (`ZIP_STAT_FAILED`).
     - Every member's timestamp and storage mode are verified (`mtime === 1767225600`, `comp_method === ZipArchive::CM_STORE`), failing with `ZIP_MEMBER_METADATA_MISMATCH` if altered.
     - Canonical member order (`manifest.json` and payload paths sorted in byte order) is enforced (`ZIP_MEMBER_ORDER_MISMATCH`).
     - Embedded `manifest.json` has a bounded maximum size limit (`2 MB`, `MAX_MANIFEST_SIZE`) to prevent memory exhaustion attacks (`MANIFEST_EXCEEDS_SIZE_LIMIT`).
     - Embedded `manifest.json` raw bytes are compared directly against the deterministic canonical serialization of the validated supplied manifest, preventing subtle JSON object/array normalization tampering (`FINAL_MANIFEST_MISMATCH`).
     - Embedded `manifest.json` is strictly validated via `PackageManifest::from_array()` and confirmed to match the caller's expected manifest exactly (`mode`, `source`, and entry records), preventing metadata injection or tampering (`FINAL_MANIFEST_MISMATCH`).
     - Each member stream is read and its SHA-256 checksum and byte size are re-verified independently before publication.
     - Verified archive size and SHA-256 digest are computed directly from the private temporary archive prior to publication (`ARCHIVE_STAT_FAILED`, `ARCHIVE_DIGEST_FAILED`), preventing TOCTOU races.
6. **Atomic No-Clobber Publication & Filesystem Boundary**:
   - Requires that the staging workspace and destination directory reside on the same filesystem device (`stat['dev']`); cross-device publication fails closed (`ATOMIC_PUBLICATION_UNAVAILABLE`).
   - Destination file or dangling link must not exist; `PackageArchiveWriter` refuses to overwrite existing files (`DESTINATION_ALREADY_EXISTS`).
   - Publication uses atomic hardlink creation (`link()`) followed by unlinking the temporary source archive. Because `link()` fails immediately if the target already exists, this eliminates TOCTOU overwrite windows and race conditions without unsafe partial file copying.
7. **Failure Cleanup Guarantee**:
   - Any failure during staging, ZIP packing, verification, or publication cleans up only the writer-owned staging files, temporary archives, and the exclusive per-operation subdirectory. Caller files, sentinels, and pre-existing workspace contents remain untouched.

---

## 6. Inbound Package Archive Inspector (`PackageArchiveInspector`)

`SiteConvoy\Package\PackageArchiveInspector` implements SiteConvoy's independent, read-only inspection and verification gateway for incoming v1 ZIP migration packages:

### Key Design & Safety Invariants
1. **Read-Only Non-Destructive Inspection & Explicit RDONLY Capability**:
   - Accepts a local regular file path and requires an explicit, verified `ZipArchive::RDONLY` capability. Fails closed upfront with `ZIP_READONLY_UNAVAILABLE` if the runtime cannot guarantee strictly read-only ZIP operations; never falls back to zero-flag open.
   - Never writes to the local filesystem, extracts files (`extractTo()`), creates temporary staging workspaces, modifies file permissions, or deletes files.
   - Zero persistence side-effects: no options, posts, or database tables are queried or modified.
2. **Path and Platform Safety**:
   - Inputs must be ordinary regular files with positive size (`INVALID_ARCHIVE_SIZE`). Rejects directories, devices, stream wrappers (`php://`, `phar://`, `file://`), symbolic link leaves, and paths with symlinked ancestors.
   - Unlike `PackageArchiveWriter` which fails closed on Windows due to POSIX permissions requirements, `PackageArchiveInspector` is fully supported on Windows when `ZipArchive::RDONLY` is available because it performs purely read-only operations and does not create new files.
3. **Structure & Member Verification**:
   - Enforces two-pass enumeration: first checks every member name for duplicates (`ZIP_DUPLICATE_MEMBER`) to prevent ZIP duplicate-entry alias attacks before opening any member streams.
   - Exactly one `manifest.json` and declared payload entries under `database/`, `uploads/`, or `assets/` must be present. Rejects missing, unmanifested extra, directory (`/`), symbolic link (`0120000`), or non-regular file members.
   - Enforces canonical member sort order: byte-sorted matching `manifest.json` and declared entries.
   - Verifies deterministic fixed mtime (`1767225600`), STORE compression (`ZipArchive::CM_STORE`), exact UNIX host OS (`ZipArchive::OPSYS_UNIX`), and exact canonical UNIX regular file attributes (`0100644 << 16` / `020151000000`), failing with `ZIP_MEMBER_METADATA_MISMATCH` if altered.
   - Strictly prohibits encrypted members (`ZIP_ENCRYPTION_PROHIBITED`).
4. **Manifest Schema & Canonical Byte Equivalence**:
   - Manifest size is bounded by the metadata safety limit (`2 MiB`, `MAX_MANIFEST_SIZE`) to prevent memory exhaustion attacks.
   - Strictly validates JSON syntax and rejects duplicate JSON object keys (`MANIFEST_DUPLICATE_KEY`).
   - Distinguishes JSON objects from arrays (ensuring `entries` is a JSON array).
   - Validates schema and topology constraints via `PackageManifest::from_array()`.
   - Compares raw manifest bytes byte-for-byte against deterministic canonical serialization (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`), rejecting non-canonical formatting, whitespace, or key ordering.
5. **Bounded-Memory Stream Verification & File Identity Stability**:
   - Each payload member stream is read incrementally in 64 KB chunks, calculating SHA-256 and byte count. Stalled reads or I/O failures fail closed (`ARCHIVE_READ_ERROR`, `ZIP_MEMBER_READ_ERROR`).
   - Memory consumption scales with chunk size + bounded manifest metadata, never with payload size.
   - Whole-file SHA-256 digest and file attributes (`size`, `mtime`, `dev`, `ino`) are snapshotted prior to ZIP opening and independently re-verified after ZIP inspection. If file attributes or file digest mutate during inspection, fails closed with `ARCHIVE_CHANGED_DURING_INSPECTION`.
   - Returns immutable `PackageArchiveInspectionResult`.
6. **Authentication vs Integrity Boundary**:
   - Inspection proves format conformity and internal SHA-256 integrity only. It does **not** assert package authenticity, authorization, or trust.

---

## 7. Isolated Package Archive Payload Stager (`PackageArchiveStager`)

`SiteConvoy\Package\PackageArchiveStager` implements SiteConvoy's secure, isolated ZIP payload staging primitive:

### Key Design & Safety Invariants
1. **Isolated Private Staging Workspace & Ownership Checks**:
   - Requires an explicit local archive path, an explicit caller-owned trusted private staging parent directory, and a caller-supplied 64-character lowercase hex expected archive SHA-256 digest.
   - On POSIX, validates that the staging parent directory exists, is a genuine directory, is not symlinked (nor contains any symlinked ancestors), and has private restrictive permissions (`0700`). Permissive staging parents fail closed (`STAGING_PARENT_NOT_PRIVATE`).
   - Validates that the staging parent does not reside within known WordPress install paths (`ABSPATH`) or public content/uploads directories (`WP_CONTENT_DIR`), failing closed with `STAGING_PARENT_IN_PUBLIC_LOCATION`.
   - Validates effective process ownership (`posix_geteuid()`) of the staging parent directory where available; unverified ownership fails closed (`STAGING_PARENT_OWNERSHIP_UNVERIFIED`).
   - Allocates an exclusive per-operation staging directory (`mkdir(..., 0700)`) with cryptographically secure random names (`sc_stage_<random>`).
   - Restrictive directory permissions (`0700`) and file permissions (`0600`) are explicitly enforced and re-verified both before writing payload bytes and after writing, regardless of process umask (e.g. `0000`).
2. **Platform Security Boundary**:
   - Fails closed immediately on native Windows environments with `SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE` before making any directory or file mutations, due to unverified NTFS access control lists.
3. **Verified Input, Pre-Extraction Recheck & Streaming Extraction**:
   - Pre-validates the archive using `PackageArchiveInspector::inspect()` before creating any workspace directories.
   - Compares the caller's expected archive digest against the verified archive digest using constant-time `hash_equals()`.
   - Re-verifies source archive stat attributes and SHA-256 digest immediately before opening and staging payload bytes, detecting any swap or TOCTOU alteration (`ARCHIVE_CHANGED_BEFORE_STAGING`).
   - Never calls `ZipArchive::extractTo()` or OS shell unzip utilities.
   - Reads each declared regular payload entry from `ZipArchive` in bounded 64 KB memory chunks, copying bytes to exclusively created destination files (`fopen(..., 'xb')`).
   - Verifies exact copied byte counts and SHA-256 hashes against the validated manifest during streaming.
   - Only manifest-declared regular files under `database/`, `uploads/`, or `assets/` are staged. `manifest.json` is not staged as a file.
   - Preserves successful zero-entry staging workspaces for valid empty manifests without triggering spurious cleanup.
4. **Filesystem Containment**:
   - Rejects path traversal (`..`), backslashes, absolute paths, and null bytes.
   - Ensures destination files and created subdirectories remain strictly contained inside the exclusive operation staging directory.
5. **Archive Stability & Verified Failure Cleanup**:
   - Verifies file identity attributes (`size`, `mtime`, `dev`, `ino`) and SHA-256 checksum of the source archive post-staging. If the source archive mutates during staging, fails closed with `ARCHIVE_CHANGED_DURING_STAGING`.
   - On any failure or exception, cleans up only the operation-owned files, subdirectories, and staging root. Caller sentinels, parent workspaces, and unrelated directories remain untouched.
   - Verifies that cleanup succeeded by confirming complete removal of all created files, directories, and the operation root. If automated cleanup cannot be verified, fails closed with a distinct `MANUAL_RECOVERY_REQUIRED` error and exposes the recovery locator.
6. **Immutable Outcome Ledger**:
   - Returns immutable `PackageArchiveStagingResult` exposing the staging root path, validated manifest, staged count, staged bytes, verified archive digest, and relative-to-staged path ledger.

---

## 8. What Is NOT Built in M2-004

The following capabilities are explicitly outside the scope of `SITECONVOY-M2-004`:
- **Live Site Mutation & Import Cutover**: Staged files are isolated in the private staging directory. No files are moved into live `wp-content`, uploads, themes, or plugins.
- **Database Import & Table Execution**: No SQL statements or tables are executed or imported into WordPress.
- **Migration Authorization & Trust Policy**: Expected digest verification checks checksum integrity, not publisher identity or cryptographic authorization.
- **REST / WP-CLI Endpoints & Background Cron**: The stager is an internal PHP class without web-accessible endpoints or background cron jobs.
- **Deployment & Site Creation**: No WordPress sites, subsites, or options are modified or created.
