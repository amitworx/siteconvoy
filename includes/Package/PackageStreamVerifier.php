<?php
/**
 * Package Stream Verifier.
 *
 * Implements bounded-memory incremental SHA-256 stream integrity verification
 * for SiteConvoy migration package entries.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use InvalidArgumentException;

/**
 * Class PackageStreamVerifier
 */
class PackageStreamVerifier {

	/**
	 * Default stream read chunk size (64 KB).
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 65536;

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
	 * Verify supplied stream map against manifest entries.
	 *
	 * @param PackageManifest         $manifest   Package manifest.
	 * @param array<string, resource> $stream_map Map of relative path to readable stream resource.
	 * @param bool                    $close_streams Whether to close streams upon completion (default true).
	 * @return PackageVerificationResult Verification outcome.
	 */
	public function verify( PackageManifest $manifest, array $stream_map, $close_streams = true ) {
		$errors         = array();
		$verified_count = 0;
		$manifest_paths = array();

		// Check for unexpected extra streams in map.
		$entries = $manifest->get_entries();
		foreach ( $entries as $entry ) {
			$manifest_paths[ $entry['path'] ] = true;
		}

		foreach ( array_keys( $stream_map ) as $stream_path ) {
			if ( ! isset( $manifest_paths[ $stream_path ] ) ) {
				$errors[] = array(
					'code'    => 'UNEXPECTED_STREAM',
					'path'    => $stream_path,
					'message' => sprintf( 'Stream map contains unexpected entry "%s" not declared in manifest.', $stream_path ),
				);
			}
		}

		try {
			// Verify each entry in deterministic manifest order.
			foreach ( $entries as $entry ) {
				$path          = $entry['path'];
				$expected_size = $entry['size'];
				$expected_hash = $entry['sha256'];

				if ( ! array_key_exists( $path, $stream_map ) ) {
					$errors[] = array(
						'code'    => 'STREAM_MISSING',
						'path'    => $path,
						'message' => sprintf( 'No stream supplied for declared manifest entry "%s".', $path ),
					);
					continue;
				}

				$stream = $stream_map[ $path ];
				if ( ! is_resource( $stream ) || 'stream' !== get_resource_type( $stream ) ) {
					$errors[] = array(
						'code'    => 'INVALID_STREAM_RESOURCE',
						'path'    => $path,
						'message' => sprintf( 'Supplied stream for "%s" is not a valid stream resource.', $path ),
					);
					continue;
				}

				$stream_result = $this->verify_single_stream( $stream, $expected_size, $expected_hash, $path );
				if ( ! $stream_result['valid'] ) {
					$errors[] = array(
						'code'    => $stream_result['code'],
						'path'    => $path,
						'message' => $stream_result['message'],
					);
				} else {
					++$verified_count;
				}
			}
		} finally {
			if ( $close_streams ) {
				foreach ( $stream_map as $stream ) {
					if ( is_resource( $stream ) ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
						fclose( $stream );
					}
				}
			}
		}

		$is_success = ( 0 === count( $errors ) && count( $entries ) === $verified_count );

		return new PackageVerificationResult( $is_success, $verified_count, $errors );
	}

	/**
	 * Verify a single stream using incremental SHA-256.
	 *
	 * @param resource $stream Stream resource.
	 * @param int      $expected_size Expected byte length.
	 * @param string   $expected_hash Expected lowercase SHA-256 hex string.
	 * @param string   $path Path for context.
	 * @return array{valid: bool, code: string, message: string} Outcome.
	 */
	private function verify_single_stream( $stream, $expected_size, $expected_hash, $path ) {
		$hash_ctx   = hash_init( 'sha256' );
		$bytes_read = 0;

		while ( ! feof( $stream ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $stream, $this->chunk_size );
			if ( false === $chunk ) {
				return array(
					'valid'   => false,
					'code'    => 'STREAM_READ_ERROR',
					'message' => sprintf( 'Read failure encountered on stream "%s".', $path ),
				);
			}

			if ( '' === $chunk ) {
				break;
			}

			$chunk_len   = strlen( $chunk );
			$bytes_read += $chunk_len;

			// Fail fast if stream exceeds declared size.
			if ( $bytes_read > $expected_size ) {
				return array(
					'valid'   => false,
					'code'    => 'SIZE_MISMATCH',
					'message' => sprintf( 'Stream "%s" exceeded declared size %d bytes.', $path, $expected_size ),
				);
			}

			hash_update( $hash_ctx, $chunk );
		}

		if ( $bytes_read !== $expected_size ) {
			return array(
				'valid'   => false,
				'code'    => 'SIZE_MISMATCH',
				'message' => sprintf( 'Stream "%s" size %d bytes does not match declared size %d bytes.', $path, $bytes_read, $expected_size ),
			);
		}

		$actual_hash = hash_final( $hash_ctx );
		if ( ! hash_equals( strtolower( $expected_hash ), strtolower( $actual_hash ) ) ) {
			return array(
				'valid'   => false,
				'code'    => 'CHECKSUM_MISMATCH',
				'message' => sprintf( 'Stream "%s" SHA-256 checksum mismatch.', $path ),
			);
		}

		return array(
			'valid'   => true,
			'code'    => '',
			'message' => '',
		);
	}
}
