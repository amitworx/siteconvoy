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
	 * @param string                  $staging_dir  Explicit temporary staging directory path.
	 * @param bool                    $close_streams Whether to close stream handles upon completion.
	 * @return PackageArchiveWriterResult Outcome.
	 */
	public function write( PackageManifest $manifest, array $stream_map, $output_path, $staging_dir, $close_streams = true ) {
		// 1. Check runtime capability: ZipArchive must exist.
		if ( ! $this->is_zip_available() ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'ZIP_CAPABILITY_UNAVAILABLE',
				'PHP ZipArchive extension is not loaded in this environment.'
			);
		}

		// 2. Validate output path.
		if ( ! is_string( $output_path ) || '' === trim( $output_path ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'INVALID_OUTPUT_PATH',
				'Destination archive path cannot be empty.'
			);
		}

		// Check for existing destination file (no clobber / do not overwrite).
		if ( file_exists( $output_path ) ) {
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

		// Reject output path if it is a directory or symlink.
		if ( is_link( $output_path ) || is_link( $output_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'SYMLINK_PATH_PROHIBITED',
				'Destination path or directory must not be a symbolic link.'
			);
		}

		// 3. Validate staging directory.
		if ( ! is_string( $staging_dir ) || '' === trim( $staging_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'INVALID_STAGING_PATH',
				'Staging directory path cannot be empty.'
			);
		}

		if ( is_link( $staging_dir ) ) {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			return PackageArchiveWriterResult::failure(
				'SYMLINK_PATH_PROHIBITED',
				'Staging directory must not be a symbolic link.'
			);
		}

		// Create private staging directory if it does not exist.
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
			$this->clean_dir( $staging_dir );
			return PackageArchiveWriterResult::failure(
				'STAGING_DIRECTORY_UNWRITABLE',
				'Staging directory is not writable.'
			);
		}

		$temp_archive_path = $staging_dir . DIRECTORY_SEPARATOR . 'sc_pkg_' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		$staged_files      = array();

		try {
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
							'Duplicate stream handle delivered to multiple entries.'
						);
					}
					$seen_handles[ $handle_id ] = true;
				}
			}

			// Stage and verify each entry.
			$staged_map = array();
			foreach ( $entries as $index => $entry ) {
				$rel_path      = $entry['path'];
				$expected_size = $entry['size'];
				$expected_hash = $entry['sha256'];

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

				// Enforce stream wrapper allowlist.
				$meta         = stream_get_meta_data( $stream );
				$mode         = isset( $meta['mode'] ) ? (string) $meta['mode'] : '';
				$wrapper_type = isset( $meta['wrapper_type'] ) ? strtolower( (string) $meta['wrapper_type'] ) : '';
				$stream_type  = isset( $meta['stream_type'] ) ? strtoupper( (string) $meta['stream_type'] ) : '';
				$uri          = isset( $meta['uri'] ) ? (string) $meta['uri'] : '';

				if ( ! str_contains( $mode, 'r' ) && ! str_contains( $mode, '+' ) ) {
					return PackageArchiveWriterResult::failure(
						'UNREADABLE_STREAM',
						'Stream is not opened in a readable mode.'
					);
				}

				$is_safe = false;
				if ( 'plainfile' === $wrapper_type && 'STDIO' === $stream_type ) {
					$is_safe = true;
				} elseif ( 'php' === $wrapper_type && in_array( $stream_type, array( 'TEMP', 'MEMORY' ), true ) ) {
					if ( ! str_starts_with( $uri, 'php://filter' ) ) {
						$is_safe = true;
					}
				}

				if ( ! $is_safe ) {
					return PackageArchiveWriterResult::failure(
						'DISALLOWED_STREAM_WRAPPER',
						'Stream uses an unsupported or non-local stream wrapper.'
					);
				}

				// Staging filename is an opaque hash, never derived from entry path.
				$staged_file = $staging_dir . DIRECTORY_SEPARATOR . 'entry_' . $index . '_' . bin2hex( random_bytes( 8 ) ) . '.dat';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$stage_fp = fopen( $staged_file, 'wb' );
				if ( false === $stage_fp ) {
					return PackageArchiveWriterResult::failure(
						'STAGING_FILE_CREATE_FAILED',
						'Failed to create opaque payload staging file.'
					);
				}
				$staged_files[] = $staged_file;

				$stage_result = $this->stream_and_verify_payload( $stream, $stage_fp, $expected_size, $expected_hash );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $stage_fp );

				if ( ! $stage_result['valid'] ) {
					return PackageArchiveWriterResult::failure(
						$stage_result['code'],
						$stage_result['message']
					);
				}

				$staged_map[ $rel_path ] = $staged_file;
			}

			// 5. Build ZIP archive.
			$zip = new ZipArchive();
			$res = $zip->open( $temp_archive_path, ZipArchive::CREATE | ZipArchive::EXCL );
			if ( true !== $res ) {
				return PackageArchiveWriterResult::failure(
					'ZIP_CREATE_FAILED',
					'Failed to initialize ZipArchive container.'
				);
			}

			// Add entries in deterministic sorted order.
			$all_members   = array_keys( $staged_map );
			$all_members[] = 'manifest.json';
			sort( $all_members, SORT_STRING );

			$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			foreach ( $all_members as $member_name ) {
				if ( 'manifest.json' === $member_name ) {
					$zip->addFromString( 'manifest.json', (string) $manifest_json );
					$zip->setMtimeName( 'manifest.json', self::DETERMINISTIC_MTIME );
					$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
				} else {
					$staged_source = $staged_map[ $member_name ];
					$zip->addFile( $staged_source, $member_name );
					$zip->setMtimeName( $member_name, self::DETERMINISTIC_MTIME );
					$zip->setCompressionName( $member_name, ZipArchive::CM_STORE );
				}
			}

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

			// 7. Atomic publication (rename within same filesystem).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $temp_archive_path, $output_path ) ) {
				// Fallback to copy + unlink if cross-device rename or Windows atomic rename fails.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				if ( ! copy( $temp_archive_path, $output_path ) ) {
					return PackageArchiveWriterResult::failure(
						'ARCHIVE_PUBLISH_FAILED',
						'Failed to publish finalized archive to destination location.'
					);
				}
				if ( file_exists( $temp_archive_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $temp_archive_path );
				}
			}

			$archive_size   = (int) filesize( $output_path );
			$archive_sha256 = (string) hash_file( 'sha256', $output_path );

			return new PackageArchiveWriterResult(
				true,
				$output_path,
				count( $entries ),
				$archive_size,
				$archive_sha256
			);

		} finally {
			if ( $close_streams ) {
				$this->close_stream_map( $stream_map );
			}
			// Cleanup staging directory and temp archive.
			foreach ( $staged_files as $staged_file ) {
				if ( file_exists( $staged_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $staged_file );
				}
			}
			if ( file_exists( $temp_archive_path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $temp_archive_path );
			}
			$this->clean_dir( $staging_dir );
		}
	}

	/**
	 * Stream and verify payload in bounded memory.
	 *
	 * @param resource $stream Inbound stream.
	 * @param resource $stage_fp Outbound staging file handle.
	 * @param int      $expected_size Expected length.
	 * @param string   $expected_hash Expected hash.
	 * @return array{valid: bool, code: string, message: string} Outcome.
	 */
	private function stream_and_verify_payload( $stream, $stage_fp, $expected_size, $expected_hash ) {
		$hash_ctx   = hash_init( 'sha256' );
		$bytes_read = 0;

		while ( ! feof( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $stream, $this->chunk_size );
			if ( false === $chunk ) {
				return array(
					'valid'   => false,
					'code'    => 'STREAM_READ_ERROR',
					'message' => 'Read failure encountered on payload stream.',
				);
			}

			if ( '' === $chunk ) {
				if ( ! feof( $stream ) ) {
					return array(
						'valid'   => false,
						'code'    => 'STREAM_READ_STALLED',
						'message' => 'Payload stream read stalled before reaching EOF.',
					);
				}
				break;
			}

			$chunk_len   = strlen( $chunk );
			$bytes_read += $chunk_len;

			if ( $bytes_read > $expected_size ) {
				return array(
					'valid'   => false,
					'code'    => 'SIZE_MISMATCH',
					'message' => 'Stream exceeded declared size.',
				);
			}

			hash_update( $hash_ctx, $chunk );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$written = fwrite( $stage_fp, $chunk );
			if ( $written !== $chunk_len ) {
				return array(
					'valid'   => false,
					'code'    => 'STAGING_WRITE_ERROR',
					'message' => 'Failed writing chunk to staging file.',
				);
			}
		}

		if ( $bytes_read !== $expected_size ) {
			return array(
				'valid'   => false,
				'code'    => 'SIZE_MISMATCH',
				'message' => 'Stream size does not match declared size.',
			);
		}

		$actual_hash = hash_final( $hash_ctx );
		if ( ! hash_equals( strtolower( $expected_hash ), strtolower( $actual_hash ) ) ) {
			return array(
				'valid'   => false,
				'code'    => 'CHECKSUM_MISMATCH',
				'message' => 'Stream SHA-256 checksum mismatch.',
			);
		}

		return array(
			'valid'   => true,
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

				// Reject directory entries.
				if ( str_ends_with( $name, '/' ) ) {
					return array(
						'valid'   => false,
						'code'    => 'ZIP_DIRECTORY_MEMBER_PROHIBITED',
						'message' => 'Finalized ZIP must not contain directory members.',
					);
				}

				// Check external attributes for UNIX symlinks.
				$opsys = 0;
				$attr  = 0;
				if ( $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
					// High 16 bits of external attributes contain UNIX file mode.
					$mode = ( $attr >> 16 ) & 0170000;
					if ( 0120000 === $mode ) {
						return array(
							'valid'   => false,
							'code'    => 'ZIP_SYMLINK_MEMBER_PROHIBITED',
							'message' => 'Finalized ZIP must not contain symbolic link members.',
						);
					}
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
					$content = (string) stream_get_contents( $member_stream );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );

					$decoded = json_decode( $content, true );
					if ( ! is_array( $decoded ) || ! isset( $decoded['format_version'] ) ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MANIFEST_INVALID',
							'message' => 'Embedded manifest.json is invalid JSON.',
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
								'message' => 'Read failure while re-verifying ZIP member.',
							);
						}
						if ( '' !== $chunk ) {
							$bytes_read += strlen( $chunk );
							hash_update( $hash_ctx, $chunk );
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					fclose( $member_stream );

					if ( $bytes_read !== $expected_size ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MEMBER_SIZE_MISMATCH',
							'message' => 'Member in finalized ZIP does not match expected size.',
						);
					}

					$actual_hash = hash_final( $hash_ctx );
					if ( ! hash_equals( strtolower( $expected_hash ), strtolower( $actual_hash ) ) ) {
						return array(
							'valid'   => false,
							'code'    => 'FINAL_MEMBER_HASH_MISMATCH',
							'message' => 'Member in finalized ZIP checksum mismatch.',
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
	 * Remove a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 */
	private function clean_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_scandir
		$items = scandir( $dir );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if ( is_dir( $path ) ) {
					$this->clean_dir( $path );
				} elseif ( file_exists( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $path );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
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
}
