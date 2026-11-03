<?php
/**
 * Package Archive Writer.
 *
 * Implements bounded-memory streaming ZIP archive creation, fail-closed pre-validation,
 * SHA-256 verification, canonical deterministic metadata, safe workspace staging,
 * independent archive verification, and atomic publication.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use InvalidArgumentException;
use ZipArchive;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PackageArchiveWriter
 */
class PackageArchiveWriter {

	/**
	 * Default stream read chunk size (64 KB).
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 65536;

	/**
	 * Canonical fixed epoch mtime for deterministic archive entries (2026-01-01 00:00:00 UTC).
	 *
	 * @var int
	 */
	const DETERMINISTIC_MTIME = 1767225600;

	/**
	 * Maximum manifest metadata size permitted for embedded manifest.json (2 MB).
	 *
	 * @var int
	 */
	const MAX_MANIFEST_SIZE = 2097152;

	/**
	 * Canonical UNIX external attribute for regular files (0100644 << 16).
	 *
	 * @var int
	 */
	const DETERMINISTIC_REGULAR_FILE_ATTR = 020151000000;

	/**
	 * Chunk size in bytes.
	 *
	 * @var int
	 */
	private $chunk_size;

	/**
	 * Constructor.
	 *
	 * @param int $chunk_size Chunk size in bytes.
	 */
	public function __construct( $chunk_size = self::DEFAULT_CHUNK_SIZE ) {
		$this->chunk_size = ( $chunk_size > 0 ) ? $chunk_size : self::DEFAULT_CHUNK_SIZE;
	}

	/**
	 * Write and verify a package ZIP archive.
	 *
	 * @param PackageManifest         $manifest     Package manifest.
	 * @param array<string, resource> $stream_map   Map of relative path to readable stream resource.
	 * @param string                  $output_path  Explicit destination file path.
	 * @param string                  $staging_dir  Explicit temporary staging parent directory path.
	 * @param bool                    $close_streams Whether to close stream handles upon completion.
	 * @return PackageArchiveWriterResult Outcome.
	 */
	public function write( PackageManifest $manifest, array $stream_map, $output_path, $staging_dir, $close_streams = true ) {
		// 1. Check runtime platform security: Windows hosts cannot currently enforce verified NTFS ACL privacy.
		if ( $this->is_windows_platform() ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE',
				'Package archive creation is unavailable on Windows environments due to unverified NTFS access control lists.'
			);
		}

		// 2. Check runtime capability: ZipArchive must exist.
		if ( ! $this->is_zip_available() ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'ZIP_CAPABILITY_UNAVAILABLE',
				'PHP ZipArchive extension is not loaded in this environment.'
			);
		}

		// 3. Validate output path.
		if ( ! is_string( $output_path ) || '' === trim( $output_path ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'INVALID_OUTPUT_PATH',
				'Destination archive path cannot be empty.'
			);
		}

		// Reject stream wrappers or URL protocols in output path.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $output_path ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'INVALID_OUTPUT_PATH',
				'Destination archive path cannot be a stream wrapper or URL protocol.'
			);
		}

		// Check for existing destination file or dangling link (no clobber / do not overwrite).
		if ( file_exists( $output_path ) || is_link( $output_path ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'DESTINATION_ALREADY_EXISTS',
				'Destination archive file already exists and cannot be overwritten.'
			);
		}

		// Check output parent directory.
		$output_dir = dirname( $output_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_dir( $output_dir ) || ! is_writable( $output_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'DESTINATION_DIRECTORY_UNWRITABLE',
				'Destination parent directory does not exist or is not writable.'
			);
		}

		// Reject output path or parent if any ancestor or the path itself is a symlink.
		if ( $this->is_or_has_symlink( $output_path ) || $this->is_or_has_symlink( $output_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'SYMLINK_PATH_PROHIBITED',
				'Destination path or parent directory must not contain symbolic links.'
			);
		}

		// 4. Validate staging directory.
		if ( ! is_string( $staging_dir ) || '' === trim( $staging_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'INVALID_STAGING_PATH',
				'Staging directory path cannot be empty.'
			);
		}

		// Reject stream wrappers or URL protocols in staging path.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $staging_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'INVALID_STAGING_PATH',
				'Staging directory path cannot be a stream wrapper or URL protocol.'
			);
		}

		if ( $this->is_or_has_symlink( $staging_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'SYMLINK_PATH_PROHIBITED',
				'Staging directory or ancestor must not contain symbolic links.'
			);
		}

		// Create private staging parent directory if it does not exist.
		if ( ! is_dir( $staging_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! mkdir( $staging_dir, 0700, true ) && ! is_dir( $staging_dir ) ) {
				if ( $close_streams ) {
					$this->close_stream_map( $stream_map );
				}
				return PackageArchiveWriterResult::failure(
					'STAGING_DIRECTORY_CREATE_FAILED',
					'Failed to create private staging directory.'
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_writable( $staging_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'STAGING_DIRECTORY_UNWRITABLE',
				'Staging directory is not writable.'
			);
		}

		// Verify filesystem device equivalence: staging and destination must reside on the same filesystem.
		$output_dir_stat  = stat( $output_dir );
		$staging_dir_stat = stat( $staging_dir );
		if ( false === $output_dir_stat || false === $staging_dir_stat || $output_dir_stat['dev'] !== $staging_dir_stat['dev'] ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'ATOMIC_PUBLICATION_UNAVAILABLE',
				'Staging workspace and destination directory must reside on the same filesystem device.'
			);
		}

		// Allocate exclusive, unique restricted 0700 per-operation staging subdirectory.
		$op_subdir         = null;
		$temp_archive_path = null;
		$staged_files      = array();

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			try {
				$candidate = $staging_dir . DIRECTORY_SEPARATOR . 'sc_op_' . bin2hex( $this->generate_random_bytes( 12 ) );
			} catch ( \Exception $e ) {
				if ( $close_streams ) {
					$this->close_stream_map( $stream_map );
				}
				return PackageArchiveWriterResult::failure(
					'RANDOM_BYTES_FAILED',
					'Cryptographically secure random bytes unavailable.'
				);
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( @mkdir( $candidate, 0700 ) ) {
				$op_subdir = $candidate;
				break;
			}
		}

		if ( null === $op_subdir || ! is_dir( $op_subdir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'STAGING_SUBDIRECTORY_CREATE_FAILED',
				'Failed to allocate an exclusive per-operation staging directory.'
			);
		}

		try {
			try {
				$temp_archive_path = $op_subdir . DIRECTORY_SEPARATOR . 'sc_pkg_' . bin2hex( $this->generate_random_bytes( 8 ) ) . '.tmp';
			} catch ( \Exception $e ) {
				return PackageArchiveWriterResult::failure(
					'RANDOM_BYTES_FAILED',
					'Cryptographically secure random bytes unavailable.'
				);
			}

			// 4. Stream verification and staging.
			$entries        = $manifest->get_entries();
			$manifest_paths = array();
			foreach ( $entries as $entry ) {
				$manifest_paths[ $entry['path'] ] = true;
			}

			// Validate complete stream set: no unexpected streams.
			foreach ( array_keys( $stream_map ) as $stream_path ) {
				if ( ! isset( $manifest_paths[ $stream_path ] ) ) {
					return PackageArchiveWriterResult::failure(
						'UNEXPECTED_STREAM',
						'Stream map contains an unexpected stream entry not declared in manifest.'
					);
				}
			}

			// Check for duplicate stream handles.
			$seen_handles = array();
			foreach ( $stream_map as $stream_res ) {
				if ( is_resource( $stream_res ) ) {
					$handle_id = (int) $stream_res;
					if ( isset( $seen_handles[ $handle_id ] ) ) {
						return PackageArchiveWriterResult::failure(
							'DUPLICATE_STREAM_RESOURCE',
							'Duplicate stream handle supplied for multiple manifest entries.'
						);
					}
					$seen_handles[ $handle_id ] = true;
				}
			}

			$staged_map = array();

			// Stage and verify each entry chunk-by-chunk.
			foreach ( $entries as $entry ) {
				$rel_path      = $entry['path'];
				$expected_size = $entry['size'];
				$expected_hash = strtolower( $entry['sha256'] );

				if ( ! array_key_exists( $rel_path, $stream_map ) ) {
					return PackageArchiveWriterResult::failure(
						'STREAM_MISSING',
						'No stream supplied for declared manifest entry.'
					);
				}

				$stream = $stream_map[ $rel_path ];
				if ( ! is_resource( $stream ) || 'stream' !== get_resource_type( $stream ) ) {
					return PackageArchiveWriterResult::failure(
						'INVALID_STREAM_RESOURCE',
						'Supplied stream is not a valid stream resource.'
					);
				}

				// Check stream readability and safe wrappers allowlist.
				$meta = stream_get_meta_data( $stream );
				$mode = isset( $meta['mode'] ) ? (string) $meta['mode'] : '';
				if ( ! str_contains( $mode, 'r' ) && ! str_contains( $mode, '+' ) ) {
					return PackageArchiveWriterResult::failure(
						'UNREADABLE_STREAM',
						'Supplied stream resource is not opened in readable mode.'
					);
				}

				$wrapper_type   = isset( $meta['wrapper_type'] ) ? strtolower( (string) $meta['wrapper_type'] ) : '';
				$stream_type    = isset( $meta['stream_type'] ) ? strtoupper( (string) $meta['stream_type'] ) : '';
				$uri            = isset( $meta['uri'] ) ? (string) $meta['uri'] : '';
				$is_safe_stream = false;

				if ( 'plainfile' === $wrapper_type && 'STDIO' === $stream_type ) {
					$is_safe_stream = true;
				} elseif ( 'php' === $wrapper_type && in_array( $stream_type, array( 'TEMP', 'MEMORY' ), true ) ) {
					if ( ! str_starts_with( $uri, 'php://filter' ) ) {
						$is_safe_stream = true;
					}
				}

				if ( ! $is_safe_stream ) {
					return PackageArchiveWriterResult::failure(
						'DISALLOWED_STREAM_WRAPPER',
						'Supplied stream uses an unsupported or non-local stream wrapper.'
					);
				}

				// Generate opaque temporary staging filename.
				try {
					$staged_filename = 'stg_' . bin2hex( $this->generate_random_bytes( 12 ) ) . '.tmp';
				} catch ( \Exception $e ) {
					return PackageArchiveWriterResult::failure(
						'RANDOM_BYTES_FAILED',
						'Cryptographically secure random bytes unavailable.'
					);
				}

				$staged_path = $op_subdir . DIRECTORY_SEPARATOR . $staged_filename;

				// Exclusive create 'x' mode to prevent clobbering or link hijacking.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
				$staged_handle = @fopen( $staged_path, 'xb' );
				if ( false === $staged_handle ) {
					return PackageArchiveWriterResult::failure(
						'STAGING_FILE_CREATE_FAILED',
						'Failed to create temporary staging file exclusively.'
					);
				}
				$staged_files[] = $staged_path;

				$hash_ctx   = hash_init( 'sha256' );
				$bytes_read = 0;
				$write_fail = false;

				while ( ! feof( $stream ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$chunk = fread( $stream, $this->chunk_size );
					if ( false === $chunk ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $staged_handle );
						return PackageArchiveWriterResult::failure(
							'STREAM_READ_ERROR',
							'Encountered error while reading payload stream.'
						);
					}

					if ( '' === $chunk ) {
						if ( ! feof( $stream ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $staged_handle );
							return PackageArchiveWriterResult::failure(
								'STREAM_READ_STALLED',
								'Stream read stalled before reaching declared length.'
							);
						}
						break;
					}

					$chunk_len   = strlen( $chunk );
					$bytes_read += $chunk_len;

					if ( $bytes_read > $expected_size ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $staged_handle );
						return PackageArchiveWriterResult::failure(
							'SIZE_MISMATCH',
							'Stream length exceeded declared entry size.'
						);
					}

					hash_update( $hash_ctx, $chunk );

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					$written = fwrite( $staged_handle, $chunk );
					if ( $written !== $chunk_len ) {
						$write_fail = true;
						break;
					}
				}

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $staged_handle );

				if ( $write_fail ) {
					return PackageArchiveWriterResult::failure(
						'STAGING_WRITE_FAILED',
						'Failed to write complete payload chunk to staging file.'
					);
				}

				if ( $bytes_read !== $expected_size ) {
					return PackageArchiveWriterResult::failure(
						'SIZE_MISMATCH',
						'Stream length does not match declared entry size.'
					);
				}

				$actual_hash = hash_final( $hash_ctx );
				if ( ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) ) {
					return PackageArchiveWriterResult::failure(
						'CHECKSUM_MISMATCH',
						'Stream SHA-256 checksum does not match manifest declaration.'
					);
				}

				$staged_map[ $rel_path ] = $staged_path;
			}

			// 5. Build ZIP archive deterministically.
			$zip = new ZipArchive();
			$res = $zip->open( $temp_archive_path, ZipArchive::CREATE | ZipArchive::EXCL );
			if ( true !== $res ) {
				return PackageArchiveWriterResult::failure(
					'ZIP_OPEN_FAILED',
					'Failed to create temporary ZIP archive.'
				);
			}

			// Encode manifest.json canonical bytes.
			$manifest_json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $manifest_json || ! is_string( $manifest_json ) ) {
				$zip->close();
				return PackageArchiveWriterResult::failure(
					'MANIFEST_ENCODE_FAILED',
					'Failed to JSON encode package manifest.'
				);
			}

			// Collect all member names in canonical sort order: manifest.json + sorted payload entries.
			$sorted_paths = array_keys( $staged_map );
			sort( $sorted_paths, SORT_STRING );
			$all_member_names = array_merge( array( 'manifest.json' ), $sorted_paths );
			sort( $all_member_names, SORT_STRING );

			foreach ( $all_member_names as $member_name ) {
				if ( 'manifest.json' === $member_name ) {
					if ( ! $zip->addFromString( 'manifest.json', $manifest_json ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_ADD_MEMBER_FAILED',
							'Failed to add manifest.json member to ZIP archive.'
						);
					}
					if ( ! $this->set_member_mtime( $zip, 'manifest.json', self::DETERMINISTIC_MTIME ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_SET_METADATA_FAILED',
							'Failed to set deterministic timestamp for manifest.json.'
						);
					}
					if ( ! $this->set_member_compression( $zip, 'manifest.json', ZipArchive::CM_STORE ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_SET_METADATA_FAILED',
							'Failed to set stored compression mode for manifest.json.'
						);
					}
					if ( ! $this->set_member_external_attributes( $zip, 'manifest.json', ZipArchive::OPSYS_UNIX, self::DETERMINISTIC_REGULAR_FILE_ATTR ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_SET_METADATA_FAILED',
							'Failed to set external attributes for manifest.json.'
						);
					}
				} else {
					$staged_file = $staged_map[ $member_name ];
					if ( ! $zip->addFile( $staged_file, $member_name ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_ADD_MEMBER_FAILED',
							'Failed to add entry member to ZIP archive.'
						);
					}
					if ( ! $this->set_member_mtime( $zip, $member_name, self::DETERMINISTIC_MTIME ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_SET_METADATA_FAILED',
							'Failed to set deterministic timestamp for entry member.'
						);
					}
					if ( ! $this->set_member_compression( $zip, $member_name, ZipArchive::CM_STORE ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_SET_METADATA_FAILED',
							'Failed to set stored compression mode for entry member.'
						);
					}
					if ( ! $this->set_member_external_attributes( $zip, $member_name, ZipArchive::OPSYS_UNIX, self::DETERMINISTIC_REGULAR_FILE_ATTR ) ) {
						$zip->close();
						return PackageArchiveWriterResult::failure(
							'ZIP_SET_METADATA_FAILED',
							'Failed to set external attributes for entry member.'
						);
					}
				}
			}

			// Test seam hook before close.
			$this->before_close_zip( $zip, $temp_archive_path );

			if ( ! $this->execute_zip_close( $zip ) ) {
				return PackageArchiveWriterResult::failure(
					'ZIP_CLOSE_FAILED',
					'Failed to finalize and close ZIP archive.'
				);
			}

			// 6. Independent verification of finalized archive before publication.
			$verify_result = $this->verify_finalized_zip( $temp_archive_path, $manifest );
			if ( ! $verify_result['valid'] ) {
				return PackageArchiveWriterResult::failure(
					$verify_result['code'],
					$verify_result['message']
				);
			}

			// Enforce restrictive file permissions (0600) on temporary archive prior to publication.
			if ( ! $this->enforce_file_permissions( $temp_archive_path, 0600 ) ) {
				return PackageArchiveWriterResult::failure(
					'ARCHIVE_PERMISSIONS_FAILED',
					'Failed to enforce restrictive file permissions on finalized temporary archive.'
				);
			}

			// Pre-calculate verified archive size and digest from private temp archive prior to publication.
			$archive_size = filesize( $temp_archive_path );
			if ( false === $archive_size || $archive_size <= 0 ) {
				return PackageArchiveWriterResult::failure(
					'ARCHIVE_STAT_FAILED',
					'Failed to determine size of finalized temporary archive.'
				);
			}

			$archive_sha256 = $this->compute_archive_hash( 'sha256', $temp_archive_path );
			if ( false === $archive_sha256 || '' === $archive_sha256 ) {
				return PackageArchiveWriterResult::failure(
					'ARCHIVE_DIGEST_FAILED',
					'Failed to compute SHA-256 digest of finalized temporary archive.'
				);
			}

			// 7. Atomic no-clobber publication.
			$publish_result = $this->publish_archive_no_clobber( $temp_archive_path, $output_path );
			if ( ! $publish_result['success'] ) {
				return PackageArchiveWriterResult::failure(
					$publish_result['code'],
					$publish_result['message']
				);
			}

			return new PackageArchiveWriterResult(
				true,
				$output_path,
				count( $entries ),
				(int) $archive_size,
				(string) $archive_sha256
			);

		} finally {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}

			// Remove only writer-owned files in this operation.
			foreach ( $staged_files as $staged_file ) {
				if ( file_exists( $staged_file ) || is_link( $staged_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $staged_file );
				}
			}
			if ( null !== $temp_archive_path && ( file_exists( $temp_archive_path ) || is_link( $temp_archive_path ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $temp_archive_path );
			}

			// Remove the exclusive operation subdirectory if empty.
			if ( null !== $op_subdir && is_dir( $op_subdir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $op_subdir );
			}
		}
	}

	/**
	 * Publish archive using atomic no-clobber primitives.
	 *
	 * Uses hardlink creation (link) to guarantee no-clobber atomic entry on both Unix and Windows
	 * NTFS when operating on the same filesystem. If link() is unavailable, fails closed.
	 *
	 * @param string $source_path Finalized temporary archive path.
	 * @param string $destination_path Destination archive path.
	 * @return array{success: bool, code: string, message: string} Outcome.
	 */
	protected function publish_archive_no_clobber( $source_path, $destination_path ) {
		// Pre-check for leaf existence or link.
		if ( file_exists( $destination_path ) || is_link( $destination_path ) ) {
			return array(
				'success' => false,
				'code'    => 'DESTINATION_ALREADY_EXISTS',
				'message' => 'Destination archive file already exists and cannot be overwritten.',
			);
		}

		// Ensure hard link function is available.
		if ( ! function_exists( 'link' ) ) {
			return array(
				'success' => false,
				'code'    => 'ATOMIC_PUBLICATION_UNAVAILABLE',
				'message' => 'Atomic no-clobber publication primitive is unavailable in this environment.',
			);
		}

		// Hard link source to destination (atomic creation; fails closed if destination was created in between).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$linked = @link( $source_path, $destination_path );
		if ( ! $linked ) {
			// Determine if failure was due to racing destination creation or cross-device/permissions.
			if ( file_exists( $destination_path ) || is_link( $destination_path ) ) {
				return array(
					'success' => false,
					'code'    => 'DESTINATION_ALREADY_EXISTS',
					'message' => 'Destination archive file already exists and cannot be overwritten.',
				);
			}

			return array(
				'success' => false,
				'code'    => 'ATOMIC_PUBLICATION_FAILED',
				'message' => 'Failed to publish archive using atomic hardlink primitive.',
			);
		}

		// Remove temporary source link.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $source_path );

		// Ensure published destination archive maintains restrictive permissions.
		if ( ! $this->enforce_file_permissions( $destination_path, 0600 ) ) {
			// Fail closed: clean up published archive to avoid leaving insecure files.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $destination_path );
			return array(
				'success' => false,
				'code'    => 'ARCHIVE_PERMISSIONS_FAILED',
				'message' => 'Failed to enforce restrictive file permissions on published destination archive.',
			);
		}

		return array(
			'success' => true,
			'code'    => '',
			'message' => '',
		);
	}

	/**
	 * Verify finalized archive independently.
	 *
	 * @param string          $zip_path Path to temporary zip file.
	 * @param PackageManifest $manifest Expected manifest.
	 * @return array{valid: bool, code: string, message: string} Outcome.
	 */
	private function verify_finalized_zip( $zip_path, PackageManifest $manifest ) {
		$zip = new ZipArchive();
		$res = $zip->open( $zip_path );
		if ( true !== $res ) {
			return array(
				'valid'   => false,
				'code'    => 'FINAL_ZIP_INVALID',
				'message' => 'Finalized ZIP archive could not be opened for verification.',
			);
		}

		try {
			$expected_entries = $manifest->get_entries();
			$expected_names   = array( 'manifest.json' );
			$entries_by_path  = array();

			foreach ( $expected_entries as $entry ) {
				$expected_names[]                  = $entry['path'];
				$entries_by_path[ $entry['path'] ] = $entry;
			}
			sort( $expected_names, SORT_STRING );

			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$num_files = $zip->numFiles;
			if ( count( $expected_names ) !== $num_files ) {
				return array(
					'valid'   => false,
					'code'    => 'ZIP_MEMBER_COUNT_MISMATCH',
					'message' => 'Finalized ZIP contains unexpected or missing members.',
				);
			}

			// Expected canonical manifest raw JSON string.
			$expected_manifest_json = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			// Check member names, symlink attributes, and entry sizes.
			for ( $i = 0; $i < $num_files; $i++ ) {
				$stat = $zip->statIndex( $i );
				if ( false === $stat ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_STAT_FAILED',
						'message' => 'Failed to stat member in finalized ZIP.',
					);
				}

				$name = $stat['name'];

				// Verify member deterministic timestamp and compression method.
				if ( ! isset( $stat['mtime'] ) || self::DETERMINISTIC_MTIME !== $stat['mtime'] ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_MEMBER_METADATA_MISMATCH',
						'message' => 'Finalized ZIP member does not have deterministic timestamp.',
					);
				}

				if ( ! isset( $stat['comp_method'] ) || ZipArchive::CM_STORE !== $stat['comp_method'] ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_MEMBER_METADATA_MISMATCH',
						'message' => 'Finalized ZIP member is not stored without compression.',
					);
				}

				// Reject directory entries.
				if ( str_ends_with( $name, '/' ) ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_DIRECTORY_MEMBER_PROHIBITED',
						'message' => 'Finalized ZIP must not contain directory members.',
					);
				}

				// Check external attributes for UNIX symlinks or non-regular files.
				$opsys = 0;
				$attr  = 0;
				if ( ! $this->get_member_external_attributes( $zip, $i, $opsys, $attr ) ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_STAT_FAILED',
						'message' => 'Failed to retrieve external attributes for member in finalized ZIP.',
					);
				}

				// High 16 bits of external attributes contain UNIX file mode.
				$file_type = ( $attr >> 16 ) & 0170000;
				if ( 0120000 === $file_type ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_SYMLINK_MEMBER_PROHIBITED',
						'message' => 'Finalized ZIP must not contain symbolic link members.',
					);
				}
				// Only regular files (0100000) allowed.
				if ( 0100000 !== $file_type ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_NONREGULAR_MEMBER_PROHIBITED',
						'message' => 'Finalized ZIP must contain only regular file members.',
					);
				}

				if ( $name !== $expected_names[ $i ] ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_MEMBER_ORDER_MISMATCH',
						'message' => 'Finalized ZIP members do not follow canonical sort order.',
					);
				}

				// Verify member stream hash and size.
				$member_stream = $zip->getStream( $name );
				if ( ! is_resource( $member_stream ) ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_MEMBER_STREAM_ERROR',
						'message' => 'Failed to open stream for finalized ZIP member.',
					);
				}

				if ( 'manifest.json' === $name ) {
					// Check manifest metadata size bound.
					if ( isset( $stat['size'] ) && $stat['size'] > self::MAX_MANIFEST_SIZE ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $member_stream );
						return array(
							'valid'   => false,
							'code'    => 'MANIFEST_EXCEEDS_SIZE_LIMIT',
							'message' => 'Embedded manifest.json exceeds maximum size limit.',
						);
					}

					$content = (string) stream_get_contents( $member_stream, self::MAX_MANIFEST_SIZE + 1 );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );

					if ( strlen( $content ) > self::MAX_MANIFEST_SIZE ) {
						return array(
							'valid'   => false,
							'code'    => 'MANIFEST_EXCEEDS_SIZE_LIMIT',
							'message' => 'Embedded manifest.json exceeds maximum size limit.',
						);
					}

					// Verify exact canonical byte-for-byte equivalence against expected manifest serialization.
					if ( $content !== $expected_manifest_json ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MANIFEST_MISMATCH',
							'message' => 'Embedded manifest.json raw bytes do not match canonical manifest serialization.',
						);
					}

					$decoded = json_decode( $content, true );
					if ( ! is_array( $decoded ) ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MANIFEST_INVALID',
							'message' => 'Embedded manifest.json is invalid JSON.',
						);
					}

					// Strictly validate embedded manifest through PackageManifest::from_array().
					try {
						$embedded_manifest = PackageManifest::from_array( $decoded );
					} catch ( InvalidArgumentException $e ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MANIFEST_INVALID',
							'message' => 'Embedded manifest.json fails package manifest validation.',
						);
					}

					// Verify full equivalence to expected manifest.
					if ( ! $this->are_manifests_equivalent( $manifest, $embedded_manifest ) ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MANIFEST_MISMATCH',
							'message' => 'Embedded manifest.json does not match expected package manifest.',
						);
					}
				} else {
					$entry_decl    = $entries_by_path[ $name ];
					$expected_size = $entry_decl['size'];
					$expected_hash = $entry_decl['sha256'];

					$hash_ctx   = hash_init( 'sha256' );
					$bytes_read = 0;

					while ( ! feof( $member_stream ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
						$chunk = fread( $member_stream, $this->chunk_size );
						if ( false === $chunk || ( '' === $chunk && ! feof( $member_stream ) ) ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $member_stream );
							return array(
								'valid'   => false,
								'code'    => 'ZIP_MEMBER_READ_ERROR',
								'message' => 'Failed to read stream for finalized ZIP member.',
							);
						}

						$chunk_len   = strlen( $chunk );
						$bytes_read += $chunk_len;

						if ( $bytes_read > $expected_size ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $member_stream );
							return array(
								'valid'   => false,
								'code'    => 'ZIP_MEMBER_SIZE_MISMATCH',
								'message' => 'Member stream exceeded declared size in finalized ZIP.',
							);
						}

						hash_update( $hash_ctx, $chunk );
					}

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );

					if ( $bytes_read !== $expected_size ) {
						return array(
							'valid'   => false,
							'code'    => 'ZIP_MEMBER_SIZE_MISMATCH',
							'message' => 'Member size does not match declared size in finalized ZIP.',
						);
					}

					$actual_hash = hash_final( $hash_ctx );
					if ( ! hash_equals( strtolower( $expected_hash ), strtolower( $actual_hash ) ) ) {
						return array(
							'valid'   => false,
							'code'    => 'ZIP_MEMBER_CHECKSUM_MISMATCH',
							'message' => 'Member SHA-256 checksum mismatch in finalized ZIP.',
						);
					}
				}
			}

			return array(
				'valid'   => true,
				'code'    => '',
				'message' => '',
			);

		} finally {
			$zip->close();
		}
	}

	/**
	 * Verify that two manifests are strictly equivalent.
	 *
	 * @param PackageManifest $expected Expected manifest.
	 * @param PackageManifest $actual   Actual embedded manifest.
	 * @return bool True if identical in mode, source, and entry details.
	 */
	private function are_manifests_equivalent( PackageManifest $expected, PackageManifest $actual ) {
		if ( $expected->get_format_version() !== $actual->get_format_version() ) {
			return false;
		}

		if ( $expected->get_mode() !== $actual->get_mode() ) {
			return false;
		}

		if ( $expected->get_source() !== $actual->get_source() ) {
			return false;
		}

		$expected_entries = $expected->get_entries();
		$actual_entries   = $actual->get_entries();
		$entries_count    = count( $expected_entries );

		if ( count( $actual_entries ) !== $entries_count ) {
			return false;
		}

		for ( $i = 0; $i < $entries_count; $i++ ) {
			if ( $expected_entries[ $i ] !== $actual_entries[ $i ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a path or any of its existing ancestor directories is a symlink.
	 *
	 * @param string $path File or directory path.
	 * @return bool True if path or any existing ancestor is a symlink.
	 */
	private function is_or_has_symlink( $path ) {
		if ( is_link( $path ) ) {
			return true;
		}

		$dir = is_dir( $path ) ? $path : dirname( $path );
		while ( ! empty( $dir ) && '.' !== $dir && DIRECTORY_SEPARATOR !== $dir ) {
			if ( is_link( $dir ) ) {
				return true;
			}
			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				break;
			}
			$dir = $parent;
		}

		return false;
	}

	/**
	 * Close all stream handles in stream map.
	 *
	 * @param array<string, mixed> $stream_map Stream map.
	 */
	private function close_stream_map( array $stream_map ) {
		foreach ( $stream_map as $stream ) {
			if ( is_resource( $stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $stream );
			}
		}
	}

	/**
	 * Generate cryptographically secure random bytes (test seam).
	 *
	 * @param int $length Number of bytes.
	 * @return string Random bytes.
	 * @throws \Exception When random bytes cannot be generated.
	 */
	protected function generate_random_bytes( $length ) {
		return random_bytes( $length );
	}

	/**
	 * Set deterministic modification time for zip member (test seam).
	 *
	 * @param ZipArchive $zip Zip archive instance.
	 * @param string     $name Member name.
	 * @param int        $mtime Epoch timestamp.
	 * @return bool True on success, false on failure.
	 */
	protected function set_member_mtime( ZipArchive $zip, $name, $mtime ) {
		return $zip->setMtimeName( $name, $mtime );
	}

	/**
	 * Set compression method for zip member (test seam).
	 *
	 * @param ZipArchive $zip Zip archive instance.
	 * @param string     $name Member name.
	 * @param int        $method Compression method.
	 * @return bool True on success, false on failure.
	 */
	protected function set_member_compression( ZipArchive $zip, $name, $method ) {
		return $zip->setCompressionName( $name, $method );
	}

	/**
	 * Compute hash of archive file (test seam).
	 *
	 * @param string $algo Hash algorithm.
	 * @param string $path File path.
	 * @return string|false
	 */
	protected function compute_archive_hash( $algo, $path ) {
		return hash_file( $algo, $path );
	}

	/**
	 * Check whether current runtime platform is Windows (test seam).
	 *
	 * @return bool True if running on Windows.
	 */
	protected function is_windows_platform() {
		return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
	}

	/**
	 * Check whether ZipArchive capability is available (test seam).
	 *
	 * @return bool
	 */
	protected function is_zip_available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Hook called immediately before closing zip archive (test seam for corruption injection).
	 *
	 * @param ZipArchive $zip ZipArchive instance.
	 * @param string     $temp_archive_path Path to temp archive.
	 */
	protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
		// No-op in production.
	}

	/**
	 * Execute zip close operation (test seam for failure simulation).
	 *
	 * @param ZipArchive $zip ZipArchive instance.
	 * @return bool
	 */
	protected function execute_zip_close( ZipArchive $zip ) {
		return $zip->close();
	}

	/**
	 * Set external attributes for member in zip (test seam).
	 *
	 * @param ZipArchive $zip ZipArchive instance.
	 * @param string     $name Member name.
	 * @param int        $opsys Target OS.
	 * @param int        $attr External attributes.
	 * @return bool True on success, false on failure.
	 */
	protected function set_member_external_attributes( ZipArchive $zip, $name, $opsys, $attr ) {
		return $zip->setExternalAttributesName( $name, $opsys, $attr );
	}

	/**
	 * Get external attributes for member in zip (test seam).
	 *
	 * @param ZipArchive $zip ZipArchive instance.
	 * @param int        $index Member index.
	 * @param int        $opsys Output OS.
	 * @param int        $attr Output attributes.
	 * @return bool True on success, false on failure.
	 */
	protected function get_member_external_attributes( ZipArchive $zip, $index, &$opsys, &$attr ) {
		return $zip->getExternalAttributesIndex( $index, $opsys, $attr );
	}

	/**
	 * Enforce restrictive file permissions on archive file (test seam).
	 *
	 * @param string $path Path to archive file.
	 * @param int    $mode POSIX permission mode (e.g. 0600).
	 * @return bool True on success, false on failure.
	 */
	protected function enforce_file_permissions( $path, $mode ) {
		if ( ! file_exists( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
		$chmod_res = @chmod( $path, $mode );
		if ( ! $chmod_res ) {
			return false;
		}

		// On non-Windows (POSIX) hosts, verify the mode was actually applied.
		if ( 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			// Clear stat cache to ensure fresh permissions read.
			clearstatcache( true, $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
			$actual_perms = fileperms( $path );
			if ( false === $actual_perms || ( $actual_perms & 0777 ) !== $mode ) {
				return false;
			}
		}

		return true;
	}
}
