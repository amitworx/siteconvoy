<?php
/**
 * Package Archive Inspector.
 *
 * Implements read-only fail-closed inspection and verification for SiteConvoy v1 ZIP migration packages.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use ZipArchive;
use InvalidArgumentException;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PackageArchiveInspector
 */
class PackageArchiveInspector {

	/**
	 * Canonical deterministic member timestamp (2026-01-01 00:00:00 UTC).
	 *
	 * @var int
	 */
	const DETERMINISTIC_MTIME = 1767225600;

	/**
	 * Canonical deterministic regular file UNIX external attributes (0100644 << 16).
	 *
	 * @var int
	 */
	const DETERMINISTIC_REGULAR_FILE_ATTR = 020151000000;

	/**
	 * Maximum allowed manifest.json size in bytes (2 MiB metadata safety bound).
	 *
	 * @var int
	 */
	const MAX_MANIFEST_SIZE = 2097152;

	/**
	 * Default streaming chunk size in bytes (64 KB).
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 65536;

	/**
	 * Inspect and independently verify an incoming local ZIP migration package archive.
	 *
	 * Operates strictly read-only: does not modify or extract files, does not touch the database,
	 * does not change file permissions.
	 *
	 * @param string $archive_path Absolute or relative path to the local archive file.
	 * @return PackageArchiveInspectionResult Outcome value object.
	 */
	public function inspect( $archive_path ) {
		// 1. Check capability.
		if ( ! $this->is_zip_available() ) {
			return PackageArchiveInspectionResult::failure(
				'ZIP_CAPABILITY_UNAVAILABLE',
				'PHP ZipArchive extension is not loaded in this environment.'
			);
		}

		// Require actual runtime ZipArchive::RDONLY capability (fail closed without it).
		if ( ! $this->is_zip_readonly_available() ) {
			return PackageArchiveInspectionResult::failure(
				'ZIP_READONLY_UNAVAILABLE',
				'Explicit read-only ZIP capability is unavailable in this environment.'
			);
		}

		// 2. Validate input path.
		if ( ! is_string( $archive_path ) || '' === trim( $archive_path ) ) {
			return PackageArchiveInspectionResult::failure(
				'INVALID_ARCHIVE_PATH',
				'Archive path cannot be empty.'
			);
		}

		// Reject stream wrappers or URL protocols.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $archive_path ) ) {
			return PackageArchiveInspectionResult::failure(
				'INVALID_ARCHIVE_PATH',
				'Archive path cannot be a stream wrapper or URL protocol.'
			);
		}

		// Check existence.
		if ( ! file_exists( $archive_path ) ) {
			return PackageArchiveInspectionResult::failure(
				'ARCHIVE_NOT_FOUND',
				'Archive file does not exist.'
			);
		}

		// Reject symlink leaf.
		if ( is_link( $archive_path ) ) {
			return PackageArchiveInspectionResult::failure(
				'INVALID_ARCHIVE_TYPE',
				'Archive path cannot be a symbolic link.'
			);
		}

		// Must be a regular file, not a directory or device.
		if ( is_dir( $archive_path ) || ! is_file( $archive_path ) ) {
			return PackageArchiveInspectionResult::failure(
				'INVALID_ARCHIVE_TYPE',
				'Archive path must be an ordinary regular file.'
			);
		}

		// Check ancestor directory symlinks.
		if ( $this->has_symlinked_ancestor( $archive_path ) ) {
			return PackageArchiveInspectionResult::failure(
				'INVALID_ARCHIVE_PATH',
				'Archive path contains symbolic link ancestors.'
			);
		}

		// Stat archive file and capture initial file identity snapshot.
		$initial_stat = stat( $archive_path );
		if ( false === $initial_stat || ! isset( $initial_stat['size'] ) ) {
			return PackageArchiveInspectionResult::failure(
				'ARCHIVE_STAT_FAILED',
				'Failed to read archive file attributes.'
			);
		}
		$archive_size = (int) $initial_stat['size'];

		if ( $archive_size <= 0 ) {
			return PackageArchiveInspectionResult::failure(
				'INVALID_ARCHIVE_SIZE',
				'Archive size must be a positive integer.'
			);
		}

		// Compute initial archive SHA-256 digest via bounded streaming.
		$archive_sha256 = $this->compute_file_sha256( $archive_path );
		if ( null === $archive_sha256 ) {
			return PackageArchiveInspectionResult::failure(
				'ARCHIVE_READ_ERROR',
				'Failed to compute archive checksum.'
			);
		}

		// 3. Open ZIP archive in read-only mode.
		$zip   = new ZipArchive();
		$flags = ZipArchive::RDONLY;

		$res = $zip->open( $archive_path, $flags );
		if ( true !== $res ) {
			return PackageArchiveInspectionResult::failure(
				'ZIP_OPEN_FAILED',
				'Failed to open ZIP archive.'
			);
		}

		try {
			$verification_result = $this->verify_zip_contents( $zip, $archive_size, $archive_sha256 );
			if ( ! $verification_result->is_success() ) {
				return $verification_result;
			}
		} finally {
			$zip->close();
		}

		// Hook for testing post-ZIP verification mutations.
		$this->on_after_zip_verification();

		// 4. Verify archive identity stability after ZIP verification.
		clearstatcache( true, $archive_path );
		$final_stat = stat( $archive_path );
		if ( false === $final_stat || ! isset( $final_stat['size'] ) ) {
			return PackageArchiveInspectionResult::failure(
				'ARCHIVE_CHANGED_DURING_INSPECTION',
				'Archive file attributes changed or became unreadable during inspection.'
			);
		}

		// Compare file identity properties (size, mtime, dev, ino).
		if ( (int) $final_stat['size'] !== $archive_size
			|| $final_stat['mtime'] !== $initial_stat['mtime']
			|| ( isset( $initial_stat['ino'], $final_stat['ino'] ) && $final_stat['ino'] !== $initial_stat['ino'] )
			|| ( isset( $initial_stat['dev'], $final_stat['dev'] ) && $final_stat['dev'] !== $initial_stat['dev'] )
		) {
			return PackageArchiveInspectionResult::failure(
				'ARCHIVE_CHANGED_DURING_INSPECTION',
				'Archive file identity changed during inspection.'
			);
		}

		// Re-read digest after ZIP inspection to detect any content alteration.
		$final_sha256 = $this->compute_file_sha256( $archive_path );
		if ( null === $final_sha256 || ! hash_equals( $archive_sha256, $final_sha256 ) ) {
			return PackageArchiveInspectionResult::failure(
				'ARCHIVE_CHANGED_DURING_INSPECTION',
				'Archive checksum changed during inspection.'
			);
		}

		return $verification_result;
	}

	/**
	 * Verify ZIP contents, structure, manifest, and payload streams.
	 *
	 * @param ZipArchive $zip            Opened ZipArchive instance.
	 * @param int        $archive_size   Total archive byte size.
	 * @param string     $archive_sha256 Total archive SHA-256 digest.
	 * @return PackageArchiveInspectionResult Inspection result.
	 */
	private function verify_zip_contents( ZipArchive $zip, $archive_size, $archive_sha256 ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$num_files = $zip->numFiles;

		if ( $num_files < 1 ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_NOT_FOUND',
				'Archive contains no files.'
			);
		}

		// Pass 1: Enumerate all member names and verify uniqueness.
		$member_names   = array();
		$seen_names     = array();
		$manifest_index = null;

		for ( $i = 0; $i < $num_files; $i++ ) {
			$stat = $this->stat_member( $zip, $i );
			if ( false === $stat || ! isset( $stat['name'] ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_STAT_FAILED',
					'Failed to stat member in archive.'
				);
			}

			$name = $stat['name'];

			// Check for duplicate member names across the entire archive.
			if ( isset( $seen_names[ $name ] ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_DUPLICATE_MEMBER',
					'Archive contains duplicate member names.'
				);
			}
			$seen_names[ $name ] = true;
			$member_names[]      = $name;
		}

		// Pass 2: Verify metadata, attributes, and path validity.
		for ( $i = 0; $i < $num_files; $i++ ) {
			$stat = $this->stat_member( $zip, $i );
			if ( false === $stat || ! isset( $stat['name'] ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_STAT_FAILED',
					'Failed to stat member in archive.'
				);
			}
			$name = $stat['name'];

			// Verify deterministic timestamp.
			if ( ! isset( $stat['mtime'] ) || self::DETERMINISTIC_MTIME !== $stat['mtime'] ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_METADATA_MISMATCH',
					'Archive member does not have deterministic timestamp.'
				);
			}

			// Verify compression method (must be STORE).
			if ( ! isset( $stat['comp_method'] ) || ZipArchive::CM_STORE !== $stat['comp_method'] ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_METADATA_MISMATCH',
					'Archive member is not stored without compression.'
				);
			}

			// Require verifiable encryption metadata: must exist and be integer EM_NONE (0).
			if ( ! array_key_exists( 'encryption_method', $stat ) || ! is_int( $stat['encryption_method'] ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_ENCRYPTION_UNKNOWN',
					'Archive member encryption status could not be verified.'
				);
			}

			if ( ZipArchive::EM_NONE !== $stat['encryption_method'] ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_ENCRYPTION_PROHIBITED',
					'Archive member is encrypted, which is prohibited.'
				);
			}

			// Prohibit directory members.
			if ( str_ends_with( $name, '/' ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_DIRECTORY_MEMBER_PROHIBITED',
					'Archive contains directory members.'
				);
			}

			// Check external attributes and host OS.
			$opsys = 0;
			$attr  = 0;
			if ( ! $this->get_member_external_attributes( $zip, $i, $opsys, $attr ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_STAT_FAILED',
					'Failed to retrieve external attributes for member in archive.'
				);
			}

			// Require host OS to be UNIX.
			if ( ZipArchive::OPSYS_UNIX !== $opsys ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_METADATA_MISMATCH',
					'Archive member does not have UNIX host OS attributes.'
				);
			}

			// Check external attributes for UNIX symlinks or non-regular files.
			$file_type = ( $attr >> 16 ) & 0170000;
			if ( 0120000 === $file_type ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_SYMLINK_MEMBER_PROHIBITED',
					'Archive contains symbolic link members.'
				);
			}
			if ( 0100000 !== $file_type ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_NONREGULAR_MEMBER_PROHIBITED',
					'Archive contains non-regular members.'
				);
			}

			// Enforce exact canonical deterministic UNIX file permissions (0100644 << 16).
			if ( self::DETERMINISTIC_REGULAR_FILE_ATTR !== $attr ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_METADATA_MISMATCH',
					'Archive member does not have canonical deterministic file attributes.'
				);
			}

			// Validate entry path security policy.
			if ( 'manifest.json' === $name ) {
				$manifest_index = $i;
			} else {
				$path_val = PackageManifestValidator::validate_path( $name );
				if ( ! $path_val['valid'] ) {
					return PackageArchiveInspectionResult::failure(
						'ZIP_INVALID_ENTRY_PATH',
						'Archive member name violates package path policy.'
					);
				}
			}
		}

		if ( null === $manifest_index ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_NOT_FOUND',
				'Archive does not contain manifest.json.'
			);
		}

		// Read manifest.json stream within size limit.
		$manifest_stat = $this->stat_member( $zip, $manifest_index );
		if ( isset( $manifest_stat['size'] ) && $manifest_stat['size'] > self::MAX_MANIFEST_SIZE ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_EXCEEDS_SIZE_LIMIT',
				'Embedded manifest.json exceeds maximum size limit.'
			);
		}

		$manifest_stream = $zip->getStream( 'manifest.json' );
		if ( ! is_resource( $manifest_stream ) ) {
			return PackageArchiveInspectionResult::failure(
				'ZIP_MEMBER_STREAM_ERROR',
				'Failed to open stream for manifest.json.'
			);
		}

		$manifest_raw = (string) stream_get_contents( $manifest_stream, self::MAX_MANIFEST_SIZE + 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $manifest_stream );

		if ( strlen( $manifest_raw ) > self::MAX_MANIFEST_SIZE ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_EXCEEDS_SIZE_LIMIT',
				'Embedded manifest.json exceeds maximum size limit.'
			);
		}

		// Check for duplicate JSON keys before decode.
		if ( $this->has_duplicate_json_keys( $manifest_raw ) ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_DUPLICATE_KEY',
				'Embedded manifest.json contains duplicate keys.'
			);
		}

		// Strict decode with object/array distinction.
		$decoded_obj = json_decode( $manifest_raw, false );
		if ( ! is_object( $decoded_obj ) ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_INVALID_JSON',
				'Embedded manifest.json is invalid JSON.'
			);
		}

		// Ensure entries is an array and not an object.
		if ( ! isset( $decoded_obj->entries ) || ! is_array( $decoded_obj->entries ) ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_SCHEMA_INVALID',
				'Manifest entries property must be a JSON array.'
			);
		}

		$decoded_arr = json_decode( $manifest_raw, true );
		if ( ! is_array( $decoded_arr ) ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_INVALID_JSON',
				'Embedded manifest.json is invalid JSON.'
			);
		}

		// Validate manifest schema / format via PackageManifest::from_array().
		try {
			$manifest = PackageManifest::from_array( $decoded_arr );
		} catch ( InvalidArgumentException $e ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_SCHEMA_INVALID',
				'Embedded manifest.json failed validation.'
			);
		}

		// Verify canonical serialization byte-for-byte.
		$canonical_json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $manifest_raw !== $canonical_json ) {
			return PackageArchiveInspectionResult::failure(
				'MANIFEST_NONCANONICAL_SERIALIZATION',
				'Embedded manifest.json raw bytes do not match canonical serialization.'
			);
		}

		// Build expected member list in canonical byte order.
		$expected_entries = $manifest->get_entries();
		$expected_names   = array( 'manifest.json' );
		$entries_by_path  = array();

		foreach ( $expected_entries as $entry ) {
			$expected_names[]                  = $entry['path'];
			$entries_by_path[ $entry['path'] ] = $entry;
		}
		sort( $expected_names, SORT_STRING );

		if ( count( $expected_names ) !== $num_files ) {
			return PackageArchiveInspectionResult::failure(
				'ZIP_MEMBER_COUNT_MISMATCH',
				'Archive member count does not match declared manifest entries.'
			);
		}

		// Check member sort order.
		for ( $i = 0; $i < $num_files; $i++ ) {
			if ( $member_names[ $i ] !== $expected_names[ $i ] ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_ORDER_MISMATCH',
					'Archive members do not follow canonical sort order.'
				);
			}
		}

		// Verify each declared payload entry stream.
		foreach ( $expected_entries as $entry_decl ) {
			$entry_path    = $entry_decl['path'];
			$expected_size = $entry_decl['size'];
			$expected_hash = $entry_decl['sha256'];

			$member_stream = $zip->getStream( $entry_path );
			if ( ! is_resource( $member_stream ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_STREAM_ERROR',
					'Failed to open stream for archive member.'
				);
			}

			$hash_ctx   = hash_init( 'sha256' );
			$bytes_read = 0;

			while ( ! feof( $member_stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$chunk = fread( $member_stream, self::CHUNK_SIZE );
				if ( false === $chunk || ( '' === $chunk && ! feof( $member_stream ) ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );
					return PackageArchiveInspectionResult::failure(
						'ZIP_MEMBER_READ_ERROR',
						'Failed to read stream for archive member.'
					);
				}

				$chunk_len   = strlen( $chunk );
				$bytes_read += $chunk_len;

				if ( $bytes_read > $expected_size ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );
					return PackageArchiveInspectionResult::failure(
						'ZIP_MEMBER_SIZE_MISMATCH',
						'Archive member stream exceeded declared size.'
					);
				}

				hash_update( $hash_ctx, $chunk );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $member_stream );

			if ( $bytes_read !== $expected_size ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_SIZE_MISMATCH',
					'Archive member size does not match declared size.'
				);
			}

			$actual_hash = hash_final( $hash_ctx );
			if ( ! hash_equals( strtolower( $expected_hash ), strtolower( $actual_hash ) ) ) {
				return PackageArchiveInspectionResult::failure(
					'ZIP_MEMBER_CHECKSUM_MISMATCH',
					'Archive member SHA-256 checksum mismatch.'
				);
			}
		}

		return PackageArchiveInspectionResult::success(
			$manifest,
			count( $expected_entries ),
			$archive_size,
			$archive_sha256
		);
	}

	/**
	 * Detect duplicate keys in JSON string.
	 *
	 * @param string $json Raw JSON string.
	 * @return bool True if duplicates found, false otherwise.
	 */
	private function has_duplicate_json_keys( $json ) {
		$len       = strlen( $json );
		$pos       = 0;
		$stack     = array( array() ); // stack of objects' key sets.
		$in_string = false;
		$escape    = false;

		while ( $pos < $len ) {
			$ch = $json[ $pos ];

			if ( $in_string ) {
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $ch ) {
					$escape = true;
				} elseif ( '"' === $ch ) {
					$in_string = false;
				}
				++$pos;
				continue;
			}

			if ( '"' === $ch ) {
				// Parse string token.
				$start = $pos + 1;
				++$pos;
				$str_content = '';
				while ( $pos < $len ) {
					$c = $json[ $pos ];
					if ( $escape ) {
						$escape = false;
					} elseif ( '\\' === $c ) {
						$escape = true;
					} elseif ( '"' === $c ) {
						break;
					}
					++$pos;
				}
				$key = substr( $json, $start, $pos - $start );
				++$pos; // skip closing quote.

				// Look ahead for colon (whitespace allowed).
				while ( $pos < $len && ctype_space( $json[ $pos ] ) ) {
					++$pos;
				}
				if ( $pos < $len && ':' === $json[ $pos ] ) {
					// This string was an object property key.
					$depth = count( $stack ) - 1;
					if ( isset( $stack[ $depth ][ $key ] ) ) {
						return true;
					}
					$stack[ $depth ][ $key ] = true;
				}
				continue;
			}

			if ( '{' === $ch ) {
				$stack[] = array();
			} elseif ( '}' === $ch ) {
				if ( count( $stack ) > 1 ) {
					array_pop( $stack );
				}
			}

			++$pos;
		}

		return false;
	}

	/**
	 * Compute SHA-256 checksum of local file via bounded chunk streaming.
	 *
	 * @param string $path File path.
	 * @return string|null SHA-256 digest or null on error.
	 */
	private function compute_file_sha256( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		$handle = @fopen( $path, 'rb' );
		if ( ! is_resource( $handle ) ) {
			return null;
		}

		$hash_ctx = hash_init( 'sha256' );
		while ( ! feof( $handle ) ) {
			$chunk = $this->read_stream_chunk( $handle, self::CHUNK_SIZE );
			if ( false === $chunk || ( '' === $chunk && ! feof( $handle ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return null;
			}
			hash_update( $hash_ctx, $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		return hash_final( $hash_ctx );
	}

	/**
	 * Read a chunk from a stream handle.
	 *
	 * @param resource $handle     Stream handle.
	 * @param int      $chunk_size Chunk size.
	 * @return string|false String or false on error.
	 */
	protected function read_stream_chunk( $handle, $chunk_size ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		return fread( $handle, $chunk_size );
	}

	/**
	 * Hook called after ZIP contents verification for testing TOCTOU mutations.
	 *
	 * @return void
	 */
	protected function on_after_zip_verification() {
		// No-op in production.
	}

	/**
	 * Check if ZipArchive::RDONLY capability is available.
	 *
	 * @return bool True if available, false otherwise.
	 */
	protected function is_zip_readonly_available() {
		return defined( 'ZipArchive::RDONLY' );
	}

	/**
	 * Check whether any ancestor directory of a path is a symbolic link.
	 *
	 * @param string $path Path to check.
	 * @return bool True if any ancestor directory is a symlink, false otherwise.
	 */
	private function has_symlinked_ancestor( $path ) {
		$real = realpath( $path );
		if ( false === $real ) {
			return true;
		}

		$current = dirname( $path );
		while ( '.' !== $current && '/' !== $current && '\\' !== $current && '' !== $current ) {
			if ( is_link( $current ) ) {
				return true;
			}
			$parent = dirname( $current );
			if ( $parent === $current ) {
				break;
			}
			$current = $parent;
		}

		return false;
	}

	/**
	 * Get member external attributes with return check.
	 *
	 * @param ZipArchive $zip   ZipArchive instance.
	 * @param int        $index Member index.
	 * @param int        $opsys Output OS system.
	 * @param int        $attr  Output attributes.
	 * @return bool True on success, false on failure.
	 */
	protected function get_member_external_attributes( ZipArchive $zip, $index, &$opsys, &$attr ) {
		return $zip->getExternalAttributesIndex( $index, $opsys, $attr );
	}

	/**
	 * Stat member by index.
	 *
	 * @param ZipArchive $zip   ZipArchive instance.
	 * @param int        $index Member index.
	 * @return array<string, mixed>|false Stat array or false.
	 */
	protected function stat_member( ZipArchive $zip, $index ) {
		return $zip->statIndex( $index );
	}

	/**
	 * Check if ZipArchive extension is available.
	 *
	 * @return bool True if available, false otherwise.
	 */
	protected function is_zip_available() {
		return class_exists( ZipArchive::class );
	}
}
