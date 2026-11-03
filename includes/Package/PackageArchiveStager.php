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

		// Reject staging parent if located inside known WordPress install or public content paths.
		if ( $this->is_staging_parent_in_public_path( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_PARENT_IN_PUBLIC_LOCATION',
				'Trusted staging parent must not reside inside WordPress install or public content locations.'
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

		// Verify ownership of staging parent when available.
		if ( ! $this->verify_staging_parent_ownership( $trusted_staging_parent ) ) {
			return PackageArchiveStagingResult::failure(
				'STAGING_PARENT_OWNERSHIP_UNVERIFIED',
				'Trusted staging parent ownership could not be verified for the current effective process.'
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

		// Hook seam before reopening ZIP archive (test seam for swap injection).
		$this->before_reopen_zip();

		// Immediate revalidation of source archive identity and digest before opening/writing.
		clearstatcache( true, $archive_path );
		$mid_archive_stat = stat( $archive_path );
		if ( false === $mid_archive_stat || ! isset( $mid_archive_stat['size'] ) ) {
			return PackageArchiveStagingResult::failure(
				'ARCHIVE_STAT_FAILED',
				'Failed to stat archive file before staging.'
			);
		}

		$mid_sha256 = hash_file( 'sha256', $archive_path );
		if ( false === $mid_sha256 || ! hash_equals( $expected_archive_sha256, $mid_sha256 ) ) {
			return PackageArchiveStagingResult::failure(
				'ARCHIVE_CHANGED_BEFORE_STAGING',
				'Archive file altered between inspection and extraction start.'
			);
		}

		// 7. Allocate exclusive per-operation staging directory.
		$op_subdir          = null;
		$created_files      = array();
		$created_dirs       = array();
		$staged_ledger      = array();
		$total_staged_bytes = 0;
		$staging_completed  = false;

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

		// Enforce and verify 0700 permissions explicitly regardless of process umask.
		if ( ! $this->enforce_directory_mode( $op_subdir, 0700 ) ) {
			$this->cleanup_staging_artifacts( $created_files, $created_dirs, $op_subdir );
			return PackageArchiveStagingResult::failure(
				'PERMISSIONS_ENFORCEMENT_FAILED',
				'Failed to enforce 0700 permissions on operation root.'
			);
		}

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

					// Enforce and verify 0600 on created file BEFORE payload bytes are written.
					if ( ! $this->enforce_file_mode( $dest_path, 0600 ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $dest_handle );
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $member_stream );
						return PackageArchiveStagingResult::failure(
							'PERMISSIONS_ENFORCEMENT_FAILED',
							'Failed to verify 0600 private file permissions before writing payload.'
						);
					}

					// Copy member stream in bounded memory chunks and verify checksum.
					$copy_result = $this->copy_member_stream( $member_stream, $dest_handle, $expected_size, $expected_hash );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $dest_handle );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );

					if ( ! $copy_result['success'] ) {
						return PackageArchiveStagingResult::failure(
							$copy_result['error_code'],
							$copy_result['error_message']
						);
					}

					// Re-verify file mode after write on POSIX.
					if ( ! $this->enforce_file_mode( $dest_path, 0600 ) ) {
						return PackageArchiveStagingResult::failure(
							'PERMISSIONS_ENFORCEMENT_FAILED',
							'Failed to verify 0600 private file permissions on staged file after write.'
						);
					}

					$staged_ledger[ $rel_path ] = $dest_path;
					$total_staged_bytes        += $copy_result['bytes_read'];
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

			if ( (int) $final_archive_stat['size'] !== (int) $mid_archive_stat['size']
				|| $final_archive_stat['mtime'] !== $mid_archive_stat['mtime']
				|| ( isset( $mid_archive_stat['ino'], $final_archive_stat['ino'] ) && $final_archive_stat['ino'] !== $mid_archive_stat['ino'] )
				|| ( isset( $mid_archive_stat['dev'], $final_archive_stat['dev'] ) && $final_archive_stat['dev'] !== $mid_archive_stat['dev'] )
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

			// Mark operation as successfully completed.
			$staging_completed = true;

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
			// On any failure after workspace allocation, execute verified cleanup.
			if ( ! $staging_completed ) {
				$cleanup_ok = $this->cleanup_staging_artifacts( $created_files, $created_dirs, $op_subdir );
				if ( ! $cleanup_ok ) {
					// Cleanup failed; overwrite or return distinct manual recovery error.
					return PackageArchiveStagingResult::failure(
						'MANUAL_RECOVERY_REQUIRED',
						'Staging failed and automated cleanup could not be fully verified.',
						(string) $op_subdir
					);
				}
			}
		}
	}

	/**
	 * Copy a member stream to destination file in bounded memory chunks and verify SHA-256 checksum (test seam).
	 *
	 * @param resource $member_stream Source member stream.
	 * @param resource $dest_handle   Destination file handle.
	 * @param int      $expected_size Expected payload size.
	 * @param string   $expected_hash Expected SHA-256 hash.
	 * @return array{success: bool, bytes_read: int, error_code: string, error_message: string}
	 */
	protected function copy_member_stream( $member_stream, $dest_handle, $expected_size, $expected_hash ) {
		$hash_ctx   = hash_init( 'sha256' );
		$bytes_read = 0;
		$write_fail = false;

		while ( ! feof( $member_stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $member_stream, $this->chunk_size );
			if ( false === $chunk ) {
				return array(
					'success'       => false,
					'bytes_read'    => $bytes_read,
					'error_code'    => 'STREAM_READ_ERROR',
					'error_message' => 'Encountered error while reading payload member stream.',
				);
			}

			if ( '' === $chunk ) {
				if ( ! feof( $member_stream ) ) {
					return array(
						'success'       => false,
						'bytes_read'    => $bytes_read,
						'error_code'    => 'STREAM_READ_STALLED',
						'error_message' => 'Stream read stalled before reaching declared length.',
					);
				}
				break;
			}

			$chunk_len   = strlen( $chunk );
			$bytes_read += $chunk_len;

			if ( $bytes_read > $expected_size ) {
				return array(
					'success'       => false,
					'bytes_read'    => $bytes_read,
					'error_code'    => 'SIZE_MISMATCH',
					'error_message' => 'Extracted member stream exceeded declared manifest size.',
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

		if ( $write_fail ) {
			return array(
				'success'       => false,
				'bytes_read'    => $bytes_read,
				'error_code'    => 'STAGING_WRITE_FAILED',
				'error_message' => 'Failed to write complete payload chunk to staging file.',
			);
		}

		if ( $bytes_read !== $expected_size ) {
			return array(
				'success'       => false,
				'bytes_read'    => $bytes_read,
				'error_code'    => 'SIZE_MISMATCH',
				'error_message' => 'Extracted member size does not match declared manifest size.',
			);
		}

		$actual_hash = hash_final( $hash_ctx );
		if ( ! hash_equals( $expected_hash, strtolower( $actual_hash ) ) ) {
			return array(
				'success'       => false,
				'bytes_read'    => $bytes_read,
				'error_code'    => 'CHECKSUM_MISMATCH',
				'error_message' => 'Extracted member SHA-256 does not match declared manifest checksum.',
			);
		}

		return array(
			'success'       => true,
			'bytes_read'    => $bytes_read,
			'error_code'    => '',
			'error_message' => '',
		);
	}

	/**
	 * Clean up operation-owned files and directories and verify complete removal.
	 *
	 * @param array<int, string> $files Created files list.
	 * @param array<int, string> $dirs  Created directories list (in creation order).
	 * @param string|null        $root  Operation staging root directory.
	 * @return bool True if all artifacts and root were confirmed removed; false otherwise.
	 */
	private function cleanup_staging_artifacts( array $files, array $dirs, $root ) {
		$all_removed = true;

		// Remove created files.
		foreach ( $files as $file ) {
			if ( file_exists( $file ) || is_link( $file ) ) {
				if ( ! $this->unlink_file( $file ) ) {
					$all_removed = false;
				}
				clearstatcache( true, $file );
				if ( file_exists( $file ) || is_link( $file ) ) {
					$all_removed = false;
				}
			}
		}

		// Remove created subdirectories in reverse creation order.
		$rev_dirs = array_reverse( $dirs );
		foreach ( $rev_dirs as $dir ) {
			if ( is_dir( $dir ) && ! is_link( $dir ) ) {
				if ( ! $this->rmdir_directory( $dir ) ) {
					$all_removed = false;
				}
				clearstatcache( true, $dir );
				if ( file_exists( $dir ) || is_link( $dir ) ) {
					$all_removed = false;
				}
			}
		}

		// Remove operation root directory.
		if ( null !== $root && ( is_dir( $root ) || is_link( $root ) ) ) {
			if ( ! $this->rmdir_directory( $root ) ) {
				$all_removed = false;
			}
			clearstatcache( true, $root );
			if ( file_exists( $root ) || is_link( $root ) ) {
				$all_removed = false;
			}
		}

		return $all_removed;
	}

	/**
	 * Unlink file (test seam).
	 *
	 * @param string $file File path.
	 * @return bool
	 */
	protected function unlink_file( $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		return @unlink( $file );
	}

	/**
	 * Remove directory (test seam).
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	protected function rmdir_directory( $dir ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		return @rmdir( $dir );
	}

	/**
	 * Enforce and verify directory permission mode (test seam).
	 *
	 * @param string $dir  Directory path.
	 * @param int    $mode Permission mode (e.g. 0700).
	 * @return bool
	 */
	protected function enforce_directory_mode( $dir, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $dir, $mode );
		if ( 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			clearstatcache( true, $dir );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
			$perms = fileperms( $dir );
			return ( false !== $perms && ( $perms & 0777 ) === $mode );
		}
		return true;
	}

	/**
	 * Enforce and verify file permission mode (test seam).
	 *
	 * @param string $file File path.
	 * @param int    $mode Permission mode (e.g. 0600).
	 * @return bool
	 */
	protected function enforce_file_mode( $file, $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod, WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $file, $mode );
		if ( 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			clearstatcache( true, $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
			$perms = fileperms( $file );
			return ( false !== $perms && ( $perms & 0777 ) === $mode );
		}
		return true;
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
				if ( ! $this->enforce_directory_mode( $current, 0700 ) ) {
					return false;
				}
				$created_dirs[] = $current;
			}
		}

		return true;
	}

	/**
	 * Check whether staging parent resides inside WordPress install or public content paths.
	 *
	 * @param string $staging_parent Staging parent path.
	 * @return bool True if inside public/install location.
	 */
	private function is_staging_parent_in_public_path( $staging_parent ) {
		$real_parent = realpath( $staging_parent );
		if ( false === $real_parent ) {
			$real_parent = $staging_parent;
		}
		$norm_parent = rtrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $real_parent ), DIRECTORY_SEPARATOR );

		// Check against ABSPATH if defined.
		if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
			$real_abs = realpath( ABSPATH );
			if ( false === $real_abs ) {
				$real_abs = ABSPATH;
			}
			$norm_abs = rtrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $real_abs ), DIRECTORY_SEPARATOR );
			if ( '' !== $norm_abs && ( $norm_parent === $norm_abs || str_starts_with( $norm_parent, $norm_abs . DIRECTORY_SEPARATOR ) ) ) {
				return true;
			}
		}

		// Check against WP_CONTENT_DIR if defined.
		if ( defined( 'WP_CONTENT_DIR' ) && '' !== WP_CONTENT_DIR ) {
			$real_content = realpath( WP_CONTENT_DIR );
			if ( false === $real_content ) {
				$real_content = WP_CONTENT_DIR;
			}
			$norm_content = rtrim( str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $real_content ), DIRECTORY_SEPARATOR );
			if ( '' !== $norm_content && ( $norm_parent === $norm_content || str_starts_with( $norm_parent, $norm_content . DIRECTORY_SEPARATOR ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verify effective ownership of staging parent directory (test seam).
	 *
	 * @param string $path Staging parent path.
	 * @return bool
	 */
	protected function verify_staging_parent_ownership( $path ) {
		if ( function_exists( 'posix_geteuid' ) ) {
			clearstatcache( true, $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileowner
			$owner = fileowner( $path );
			if ( false === $owner || posix_geteuid() !== $owner ) {
				return false;
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
	 * Hook called immediately before reopening ZIP archive for extraction (test seam).
	 */
	protected function before_reopen_zip() {
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
