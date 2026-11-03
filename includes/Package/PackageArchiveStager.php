<?php
/**
 * Package Archive Stager.
 *
 * Implements secure bounded-memory ZIP payload extraction into an isolated,
 * private staging workspace with strict canonical manifest validation,
 * SHA-256 verification, path containment, and fail-closed handling.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use ZipArchive;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PackageArchiveStager
 */
class PackageArchiveStager {

	/**
	 * Default stream read chunk size (64 KB).
	 *
	 * @var int
	 */
	const CHUNK_SIZE = 65536;

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
	public function __construct( $chunk_size = self::CHUNK_SIZE ) {
		$this->chunk_size = ( $chunk_size > 0 ) ? $chunk_size : self::CHUNK_SIZE;
	}

	/**
	 * Stage manifest-declared regular payload files from a validated archive into an isolated staging workspace.
	 *
	 * @param string $archive_path           Path to local archive ZIP file.
	 * @param string $trusted_staging_parent Path to caller-owned trusted private staging parent directory.
	 * @param string $expected_archive_sha256 Expected 64-character lowercase hex SHA-256 checksum of the archive.
	 * @return PackageArchiveStagingResult Outcome value object.
	 */
	public function stage( $archive_path, $trusted_staging_parent, $expected_archive_sha256 ) {
		// 1. Check runtime platform security: Windows hosts fail closed before any directory/file mutation.
		if ( $this->is_windows_platform() ) {
			return PackageArchiveStagingResult::failure(
				'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE',
				'Package payload staging is unavailable on Windows environments due to unverified NTFS access control lists.'
			);
		}

		// 2. Check runtime capability: ZipArchive extension and RDONLY mode must be available.
		if ( ! $this->is_zip_available() ) {
			return PackageArchiveStagingResult::failure(
				'ZIP_CAPABILITY_UNAVAILABLE',
				'PHP ZipArchive extension is not loaded in this environment.'
			);
		}

		if ( ! $this->is_zip_readonly_available() ) {
			return PackageArchiveStagingResult::failure(
				'ZIP_READONLY_UNAVAILABLE',
				'Explicit read-only ZIP capability is unavailable in this environment.'
			);
		}

		// 3. Validate archive path.
		if ( ! is_string( $archive_path ) || '' === trim( $archive_path ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_ARCHIVE_PATH',
				'Archive path cannot be empty.'
			);
		}

		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $archive_path ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_ARCHIVE_PATH',
				'Archive path cannot be a stream wrapper or URL protocol.'
			);
		}

		if ( ! file_exists( $archive_path ) ) {
			return PackageArchiveStagingResult::failure(
				'ARCHIVE_NOT_FOUND',
				'Archive file does not exist.'
			);
		}

		if ( is_link( $archive_path ) || ! is_file( $archive_path ) || is_dir( $archive_path ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_ARCHIVE_TYPE',
				'Archive path must be an ordinary regular file and not a symbolic link.'
			);
		}

		if ( $this->has_symlinked_ancestor( $archive_path ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_ARCHIVE_PATH',
				'Archive path contains symbolic link ancestors.'
			);
		}

		// 4. Validate expected archive digest.
		if ( ! is_string( $expected_archive_sha256 ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $expected_archive_sha256 ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_EXPECTED_DIGEST',
				'Expected archive digest must be a 64-character lowercase hex SHA-256 string.'
			);
		}

		// 5. Validate trusted staging parent directory.
		if ( ! is_string( $trusted_staging_parent ) || '' === trim( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_STAGING_PARENT',
				'Trusted staging parent path cannot be empty.'
			);
		}

		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_STAGING_PARENT',
				'Trusted staging parent path cannot be a stream wrapper or URL protocol.'
			);
		}

		if ( ! file_exists( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_PARENT_NOT_FOUND',
				'Trusted staging parent directory does not exist.'
			);
		}

		if ( is_link( $trusted_staging_parent ) || $this->has_symlinked_ancestor( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'SYMLINK_PATH_PROHIBITED',
				'Trusted staging parent or ancestor must not be a symbolic link.'
			);
		}

		if ( ! is_dir( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'INVALID_STAGING_PARENT',
				'Trusted staging parent must be a genuine directory.'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_writable( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_PARENT_UNWRITABLE',
				'Trusted staging parent directory is not writable.'
			);
		}

		// Verify privacy of staging parent (0700 or equivalent restricted mode on POSIX).
		if ( ! $this->is_staging_parent_private( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_PARENT_NOT_PRIVATE',
				'Trusted staging parent directory must have restricted private permissions (0700).'
			);
		}

		// 6. Pre-validate archive using PackageArchiveInspector (read-only verification).
		$inspector         = $this->create_inspector();
		$inspection_result = $inspector->inspect( $archive_path );
		if ( ! $inspection_result->is_success() ) {
			return PackageArchiveStagingResult::failure(
				$inspection_result->get_error_code(),
				$inspection_result->get_error_message()
			);
		}

		// Verify caller's expected digest against inspected archive hash using constant-time comparison.
		$actual_archive_sha256 = $inspection_result->get_archive_sha256();
		if ( ! hash_equals( $expected_archive_sha256, $actual_archive_sha256 ) ) {
			return PackageArchiveStagingResult::failure(
				'ARCHIVE_DIGEST_MISMATCH',
				'Archive SHA-256 digest does not match caller expected checksum.'
			);
		}

		$manifest = $inspection_result->get_manifest();
		if ( null === $manifest ) {
			return PackageArchiveStagingResult::failure(
				'MANIFEST_UNAVAILABLE',
				'Validated manifest could not be retrieved from inspection result.'
			);
		}

		// Capture archive file identity stat before extraction.
		clearstatcache( true, $archive_path );
		$initial_archive_stat = stat( $archive_path );
		if ( false === $initial_archive_stat || ! isset( $initial_archive_stat['size'] ) ) {
			return PackageArchiveStagingResult::failure(
				'ARCHIVE_STAT_FAILED',
				'Failed to stat archive file before staging.'
			);
		}

		// 7. Allocate exclusive per-operation staging directory.
		$op_subdir          = null;
		$created_files      = array();
		$created_dirs       = array();
		$staged_ledger      = array();
		$total_staged_bytes = 0;

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			try {
				$candidate = rtrim( $trusted_staging_parent, '/\\' ) . DIRECTORY_SEPARATOR . 'sc_stage_' . bin2hex( $this->generate_random_bytes( 12 ) );
			} catch ( \Exception $e ) {
				return PackageArchiveStagingResult::failure(
					'RANDOM_BYTES_FAILED',
					'Cryptographically secure random bytes unavailable.'
				);
			}

			// Exclusive directory creation.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( @mkdir( $candidate, 0700 ) ) {
				$op_subdir = $candidate;
				break;
			}
		}

		if ( null === $op_subdir || ! is_dir( $op_subdir ) ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_SUBDIRECTORY_CREATE_FAILED',
				'Failed to allocate an exclusive per-operation staging directory.'
			);
		}

		// Enforce 0700 permissions explicitly regardless of process umask.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $op_subdir, 0700 );

		try {
			// 8. Open ZIP archive in read-only mode for member streaming.
			$zip   = new ZipArchive();
			$flags = ZipArchive::RDONLY;
			$res   = $zip->open( $archive_path, $flags );
			if ( true !== $res ) {
				return PackageArchiveStagingResult::failure(
					'ZIP_OPEN_FAILED',
					'Failed to open ZIP archive for staging.'
				);
			}

			try {
				// Re-verify that archive entry count matches inspection member count + 1 (for manifest.json).
				$expected_total_members = $inspection_result->get_member_count() + 1;
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				if ( $zip->numFiles !== $expected_total_members ) {
					return PackageArchiveStagingResult::failure(
						'ZIP_STRUCTURE_MISMATCH',
						'ZIP member count does not match inspected archive.'
					);
				}

				// Iterate over manifest entries in declared canonical order.
				$entries = $manifest->get_entries();

				foreach ( $entries as $entry ) {
					$rel_path      = $entry['path'];
					$expected_size = (int) $entry['size'];
					$expected_hash = (string) $entry['sha256'];

					// Validate relative path containment: must not contain traversal, backslashes, or absolute segments.
					if ( ! $this->is_safe_relative_path( $rel_path ) ) {
						return PackageArchiveStagingResult::failure(
							'UNSAFE_MEMBER_PATH',
							'Manifest entry relative path is unsafe.'
						);
					}

					// Build target destination path.
					$dest_path = $op_subdir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel_path );

					// Verify destination path stays strictly inside $op_subdir.
					if ( ! $this->is_path_contained( $dest_path, $op_subdir ) ) {
						return PackageArchiveStagingResult::failure(
							'PATH_TRAVERSAL_DETECTED',
							'Payload destination path traverses outside operation staging root.'
						);
					}

					// Ensure target subdirectories exist with 0700 private permissions.
					$target_dir = dirname( $dest_path );
					if ( ! is_dir( $target_dir ) ) {
						if ( ! $this->create_private_directory_hierarchy( $target_dir, $op_subdir, $created_dirs ) ) {
							return PackageArchiveStagingResult::failure(
								'DIRECTORY_CREATE_FAILED',
								'Failed to create private subdirectory hierarchy for payload entry.'
							);
						}
					}

					// Open payload stream from ZipArchive.
					$member_stream = $zip->getStream( $rel_path );
					if ( ! is_resource( $member_stream ) ) {
						return PackageArchiveStagingResult::failure(
							'ZIP_MEMBER_STREAM_ERROR',
							'Failed to open stream for archive member.'
						);
					}

					// Create destination file exclusively ('xb' mode).
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
					$dest_handle = @fopen( $dest_path, 'xb' );
					if ( false === $dest_handle ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $member_stream );
						return PackageArchiveStagingResult::failure(
							'STAGING_FILE_CREATE_FAILED',
							'Failed to create destination staging file exclusively.'
						);
					}
					$created_files[] = $dest_path;

					// Enforce 0600 on created file before payload bytes are written.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
					@chmod( $dest_path, 0600 );

					$hash_ctx   = hash_init( 'sha256' );
					$bytes_read = 0;
					$write_fail = false;

					while ( ! feof( $member_stream ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
						$chunk = fread( $member_stream, $this->chunk_size );
						if ( false === $chunk ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $dest_handle );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $member_stream );
							return PackageArchiveStagingResult::failure(
								'STREAM_READ_ERROR',
								'Encountered error while reading payload member stream.'
							);
						}

						if ( '' === $chunk ) {
							if ( ! feof( $member_stream ) ) {
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
								fclose( $dest_handle );
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
								fclose( $member_stream );
								return PackageArchiveStagingResult::failure(
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
							fclose( $dest_handle );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							fclose( $member_stream );
							return PackageArchiveStagingResult::failure(
								'SIZE_MISMATCH',
								'Extracted member stream exceeded declared manifest size.'
							);
						}

						hash_update( $hash_ctx, $chunk );

						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						$written = fwrite( $dest_handle, $chunk );
						if ( $written !== $chunk_len ) {
							$write_fail = true;
							break;
						}
					}

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $dest_handle );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );

					if ( $write_fail ) {
						return PackageArchiveStagingResult::failure(
							'STAGING_WRITE_FAILED',
							'Failed to write complete payload chunk to staging file.'
						);
					}

					if ( $bytes_read !== $expected_size ) {
						return PackageArchiveStagingResult::failure(
							'SIZE_MISMATCH',
							'Extracted member size does not match declared manifest size.'
						);
					}

					$actual_hash = hash_final( $hash_ctx );
					if ( ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) ) {
						return PackageArchiveStagingResult::failure(
							'CHECKSUM_MISMATCH',
							'Extracted member SHA-256 does not match declared manifest checksum.'
						);
					}

					// Re-verify file mode after write on POSIX.
					if ( 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
						clearstatcache( true, $dest_path );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
						$actual_perms = fileperms( $dest_path );
						if ( false === $actual_perms || ( $actual_perms & 0777 ) !== 0600 ) {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
							chmod( $dest_path, 0600 );
							clearstatcache( true, $dest_path );
							// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
							if ( ( fileperms( $dest_path ) & 0777 ) !== 0600 ) {
								return PackageArchiveStagingResult::failure(
									'PERMISSIONS_ENFORCEMENT_FAILED',
									'Failed to verify 0600 private file permissions on staged file.'
								);
							}
						}
					}

					$staged_ledger[ $rel_path ] = $dest_path;
					$total_staged_bytes        += $bytes_read;
				}
			} finally {
				$zip->close();
			}

			// Test hook seam for post-staging verification.
			$this->on_after_payload_staging();

			// 9. Post-staging verification of source archive file identity stability.
			clearstatcache( true, $archive_path );
			$final_archive_stat = stat( $archive_path );
			if ( false === $final_archive_stat || ! isset( $final_archive_stat['size'] ) ) {
				return PackageArchiveStagingResult::failure(
					'ARCHIVE_CHANGED_DURING_STAGING',
					'Archive file attributes changed or became unreadable during staging.'
				);
			}

			if ( (int) $final_archive_stat['size'] !== (int) $initial_archive_stat['size']
				|| $final_archive_stat['mtime'] !== $initial_archive_stat['mtime']
				|| ( isset( $initial_archive_stat['ino'], $final_archive_stat['ino'] ) && $final_archive_stat['ino'] !== $initial_archive_stat['ino'] )
				|| ( isset( $initial_archive_stat['dev'], $final_archive_stat['dev'] ) && $final_archive_stat['dev'] !== $initial_archive_stat['dev'] )
			) {
				return PackageArchiveStagingResult::failure(
					'ARCHIVE_CHANGED_DURING_STAGING',
					'Archive file identity changed during staging.'
				);
			}

			// Re-verify archive SHA-256 digest after staging.
			$final_sha256 = hash_file( 'sha256', $archive_path );
			if ( false === $final_sha256 || ! hash_equals( $expected_archive_sha256, $final_sha256 ) ) {
				return PackageArchiveStagingResult::failure(
					'ARCHIVE_CHANGED_DURING_STAGING',
					'Archive SHA-256 digest altered during staging.'
				);
			}

			// Staging completed successfully.
			return PackageArchiveStagingResult::success(
				$op_subdir,
				$manifest,
				count( $entries ),
				$total_staged_bytes,
				$actual_archive_sha256,
				$staged_ledger
			);

		} catch ( \Exception $e ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_EXCEPTION',
				'An unexpected exception occurred during payload staging.'
			);
		} finally {
			// On failure, cleanup exclusive operation directory and all created artifacts.
			if ( ! isset( $final_sha256 ) || false === $final_sha256 || ! hash_equals( $expected_archive_sha256, $final_sha256 ) || empty( $staged_ledger ) || count( $staged_ledger ) !== count( $manifest->get_entries() ) ) {
				$this->cleanup_staging_artifacts( $created_files, $created_dirs, $op_subdir );
			}
		}
	}

	/**
	 * Clean up operation-owned files and directories.
	 *
	 * @param array<int, string> $files Created files list.
	 * @param array<int, string> $dirs  Created directories list (in creation order).
	 * @param string|null        $root  Operation staging root directory.
	 */
	private function cleanup_staging_artifacts( array $files, array $dirs, $root ) {
		// Remove created files.
		foreach ( $files as $file ) {
			if ( file_exists( $file ) || is_link( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $file );
			}
		}

		// Remove created subdirectories in reverse creation order.
		$rev_dirs = array_reverse( $dirs );
		foreach ( $rev_dirs as $dir ) {
			if ( is_dir( $dir ) && ! is_link( $dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $dir );
			}
		}

		// Remove operation root directory.
		if ( null !== $root && is_dir( $root ) && ! is_link( $root ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
			@rmdir( $root );
		}
	}

	/**
	 * Create private directory hierarchy recursively under operation root.
	 *
	 * @param string             $target_dir   Target directory path.
	 * @param string             $op_subdir    Operation root directory path.
	 * @param array<int, string> $created_dirs Reference to list of created directories.
	 * @return bool True on success, false on failure.
	 */
	private function create_private_directory_hierarchy( $target_dir, $op_subdir, array &$created_dirs ) {
		$relative = substr( $target_dir, strlen( $op_subdir ) );
		$relative = trim( $relative, '/\\' );
		$parts    = explode( DIRECTORY_SEPARATOR, $relative );
		$current  = $op_subdir;

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$current .= DIRECTORY_SEPARATOR . $part;
			if ( ! is_dir( $current ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! @mkdir( $current, 0700 ) && ! is_dir( $current ) ) {
					return false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
				@chmod( $current, 0700 );
				$created_dirs[] = $current;
			}
		}

		return true;
	}

	/**
	 * Check whether a relative path is safe.
	 *
	 * @param string $path Relative path.
	 * @return bool True if safe.
	 */
	private function is_safe_relative_path( $path ) {
		if ( '' === $path || str_starts_with( $path, '/' ) || str_starts_with( $path, '\\' ) ) {
			return false;
		}

		if ( str_contains( $path, '\\' ) || str_contains( $path, "\0" ) ) {
			return false;
		}

		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a destination path is strictly contained within an operation root.
	 *
	 * @param string $dest_path Target path.
	 * @param string $op_root   Operation root directory.
	 * @return bool True if contained.
	 */
	private function is_path_contained( $dest_path, $op_root ) {
		$norm_dest = rtrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $dest_path ), DIRECTORY_SEPARATOR );
		$norm_root = rtrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $op_root ), DIRECTORY_SEPARATOR );

		return str_starts_with( $norm_dest, $norm_root . DIRECTORY_SEPARATOR );
	}

	/**
	 * Check whether a path or any existing ancestor directory is a symlink.
	 *
	 * @param string $path File or directory path.
	 * @return bool True if any ancestor is a symlink.
	 */
	private function has_symlinked_ancestor( $path ) {
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
	 * Check whether trusted staging parent is private (0700 mode) (test seam).
	 *
	 * @param string $path Staging parent path.
	 * @return bool
	 */
	protected function is_staging_parent_private( $path ) {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			return false;
		}

		clearstatcache( true, $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
		$perms = fileperms( $path );
		if ( false === $perms ) {
			return false;
		}

		return 0700 === ( $perms & 0777 );
	}

	/**
	 * Create PackageArchiveInspector instance (test seam).
	 *
	 * @return PackageArchiveInspector
	 */
	protected function create_inspector() {
		return new PackageArchiveInspector();
	}

	/**
	 * Hook called immediately after payload staging before final archive verification (test seam).
	 */
	protected function on_after_payload_staging() {
		// No-op in production.
	}

	/**
	 * Check whether current runtime platform is Windows (test seam).
	 *
	 * @return bool
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
	 * Check whether ZipArchive::RDONLY is available (test seam).
	 *
	 * @return bool
	 */
	protected function is_zip_readonly_available() {
		return defined( 'ZipArchive::RDONLY' );
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
}
