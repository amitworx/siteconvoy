<?php
/**
 * Package Manifest Validator.
 *
 * Implements strict path and entry validation for SiteConvoy migration packages.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use SiteConvoy\Contracts\MigrationMode;

/**
 * Class PackageManifestValidator
 */
class PackageManifestValidator {

	/**
	 * Approved root directory prefixes for package payloads.
	 *
	 * @var array<int, string>
	 */
	const APPROVED_ROOTS = array(
		'database',
		'uploads',
		'assets',
	);

	/**
	 * Prohibited filenames anywhere in package archives.
	 *
	 * @var array<int, string>
	 */
	const PROHIBITED_NAMES = array(
		'wp-config.php',
		'.env',
		'.htaccess',
		'web.config',
	);

	/**
	 * Reserved package root metadata filenames.
	 *
	 * @var array<int, string>
	 */
	const RESERVED_FILENAMES = array(
		'manifest.json',
		'checksums.sha256',
	);

	/**
	 * Windows reserved device names.
	 *
	 * @var array<int, string>
	 */
	const WINDOWS_RESERVED_DEVICES = array(
		'CON',
		'PRN',
		'AUX',
		'NUL',
		'COM1',
		'COM2',
		'COM3',
		'COM4',
		'COM5',
		'COM6',
		'COM7',
		'COM8',
		'COM9',
		'LPT1',
		'LPT2',
		'LPT3',
		'LPT4',
		'LPT5',
		'LPT6',
		'LPT7',
		'LPT8',
		'LPT9',
	);

	/**
	 * Validate a single relative entry path.
	 *
	 * @param string $path Archive relative path.
	 * @return array{valid: bool, error: string} Validation result.
	 */
	public static function validate_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return array(
				'valid' => false,
				'error' => 'Empty path.',
			);
		}

		// Reject null bytes and ASCII control characters.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return array(
				'valid' => false,
				'error' => 'Path contains Control or null character.',
			);
		}

		// Reject Windows backslashes, drive letters, and UNC prefixes.
		if ( str_contains( $path, '\\' ) || preg_match( '/^[a-zA-Z]:/', $path ) ) {
			return array(
				'valid' => false,
				'error' => 'Backslash or drive path is prohibited.',
			);
		}

		// Reject absolute paths and UNC slashes.
		if ( str_starts_with( $path, '/' ) ) {
			return array(
				'valid' => false,
				'error' => 'Absolute path is prohibited.',
			);
		}

		// Reject trailing slash.
		if ( str_ends_with( $path, '/' ) ) {
			return array(
				'valid' => false,
				'error' => 'Trailing slash indicates directory, only regular files allowed.',
			);
		}

		// Split path segments.
		$segments = explode( '/', $path );

		// Check for empty segments (double slashes //).
		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				return array(
					'valid' => false,
					'error' => 'Repeated path separators are prohibited.',
				);
			}

			// Path traversal check.
			if ( '.' === $segment || '..' === $segment ) {
				return array(
					'valid' => false,
					'error' => 'Path traversal segment "." or ".." is prohibited.',
				);
			}

			// Check Windows reserved devices.
			$segment_base = strtoupper( (string) pathinfo( $segment, PATHINFO_FILENAME ) );
			if ( in_array( $segment_base, self::WINDOWS_RESERVED_DEVICES, true ) ) {
				return array(
					'valid' => false,
					'error' => sprintf( 'Reserved device name "%s" is prohibited.', $segment ),
				);
			}
		}

		$filename = end( $segments );

		// Check reserved metadata files.
		if ( in_array( strtolower( $filename ), self::RESERVED_FILENAMES, true ) ) {
			return array(
				'valid' => false,
				'error' => sprintf( 'Reserved filename "%s" is prohibited.', $filename ),
			);
		}

		// Check prohibited files.
		if ( in_array( strtolower( $filename ), self::PROHIBITED_NAMES, true ) ) {
			return array(
				'valid' => false,
				'error' => sprintf( 'Prohibited path "%s" is not allowed in packages.', $filename ),
			);
		}

		// Verify root prefix.
		$root = $segments[0];
		if ( ! in_array( $root, self::APPROVED_ROOTS, true ) ) {
			return array(
				'valid' => false,
				'error' => sprintf( 'Unapproved root prefix "%s". Allowed roots: %s.', $root, implode( ', ', self::APPROVED_ROOTS ) ),
			);
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}

	/**
	 * Validate entry record fields.
	 *
	 * @param array<string, mixed> $entry Manifest entry array.
	 * @return array{valid: bool, error: string} Validation result.
	 */
	public static function validate_entry( array $entry ) {
		if ( ! isset( $entry['path'] ) || ! is_string( $entry['path'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Entry missing string "path".',
			);
		}

		$path_res = self::validate_path( $entry['path'] );
		if ( ! $path_res['valid'] ) {
			return $path_res;
		}

		if ( ! isset( $entry['size'] ) || ! is_int( $entry['size'] ) || $entry['size'] < 0 ) {
			return array(
				'valid' => false,
				'error' => 'Entry "size" must be a non-negative integer.',
			);
		}

		if ( ! isset( $entry['sha256'] ) || ! is_string( $entry['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $entry['sha256'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Entry "sha256" must be a 64-character lowercase hexadecimal hash.',
			);
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}
}
