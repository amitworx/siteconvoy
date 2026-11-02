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
	 * @param string    $path        Archive relative path.
	 * @param bool|null $has_intl    Optional capability flag for intl extension (defaults to runtime check).
	 * @param bool|null $has_mbstring Optional capability flag for mbstring extension (defaults to runtime check).
	 * @return array{valid: bool, error: string} Validation result.
	 */
	public static function validate_path( $path, $has_intl = null, $has_mbstring = null ) {
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

		$runtime_intl     = function_exists( 'normalizer_is_normalized' ) && defined( 'Normalizer::FORM_C' );
		$runtime_mbstring = function_exists( 'mb_convert_case' ) && defined( 'MB_CASE_FOLD' ) && function_exists( 'mb_check_encoding' );

		// Capability overrides can only disable/simulate missing support (false), never force/elevate support (true).
		$has_intl     = $runtime_intl && ( null === $has_intl || false !== $has_intl );
		$has_mbstring = $runtime_mbstring && ( null === $has_mbstring || false !== $has_mbstring );

		$is_ascii = ( 0 === preg_match( '/[^\x20-\x7E]/', $path ) );

		// Fail closed for non-ASCII paths if required Unicode extensions are missing.
		if ( ! $is_ascii ) {
			if ( ! $has_intl ) {
				return array(
					'valid' => false,
					'error' => 'Non-ASCII paths require the PHP intl extension for Unicode canonical normalization.',
				);
			}
			if ( ! $has_mbstring ) {
				return array(
					'valid' => false,
					'error' => 'Non-ASCII paths require the PHP mbstring extension for Unicode case-folding safety.',
				);
			}
		}

		// Enforce valid UTF-8 encoding.
		if ( $has_mbstring ) {
			if ( ! mb_check_encoding( $path, 'UTF-8' ) ) {
				return array(
					'valid' => false,
					'error' => 'Path must be a valid UTF-8 string.',
				);
			}
		} elseif ( false === preg_match( '//u', $path ) ) {
			return array(
				'valid' => false,
				'error' => 'Path must be a valid UTF-8 string.',
			);
		}

		// Enforce Unicode canonical composition (NFC) and reject decomposed combining marks (NFD).
		if ( $has_intl && function_exists( 'normalizer_is_normalized' ) && defined( 'Normalizer::FORM_C' ) ) {
			if ( ! normalizer_is_normalized( $path, \Normalizer::FORM_C ) ) {
				return array(
					'valid' => false,
					'error' => 'Path must be in Unicode Normalization Form C (precomposed).',
				);
			}
		}
		if ( 1 === preg_match( '/\p{M}/u', $path ) ) {
			return array(
				'valid' => false,
				'error' => 'Path must not contain decomposed Unicode combining marks.',
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

			// Reject Alternate Data Stream colon syntax in segment.
			if ( str_contains( $segment, ':' ) ) {
				return array(
					'valid' => false,
					'error' => 'Alternate data stream colon syntax is prohibited in path segments.',
				);
			}

			// Path traversal check (must precede trailing space/dot check).
			if ( '.' === $segment || '..' === $segment ) {
				return array(
					'valid' => false,
					'error' => 'Path traversal segment "." or ".." is prohibited.',
				);
			}

			// Reject Windows trailing spaces and trailing dots in path segments.
			if ( preg_match( '/[ .]$/', $segment ) ) {
				return array(
					'valid' => false,
					'error' => 'Path segment ending with space or dot is prohibited.',
				);
			}

			// Check Windows reserved devices (strip extension or multiple dots).
			$segment_base = strtoupper( (string) strtok( $segment, '.' ) );
			if ( in_array( $segment_base, self::WINDOWS_RESERVED_DEVICES, true ) ) {
				return array(
					'valid' => false,
					'error' => 'Reserved device name is prohibited.',
				);
			}
		}

		// Require at least two segments: approved root folder and regular filename.
		if ( count( $segments ) < 2 ) {
			// Check if single segment was a reserved or prohibited file for clear reporting.
			$basename = $segments[0];
			if ( in_array( strtolower( $basename ), self::RESERVED_FILENAMES, true ) ) {
				return array(
					'valid' => false,
					'error' => 'Reserved filename is prohibited.',
				);
			}
			if ( in_array( strtolower( $basename ), self::PROHIBITED_NAMES, true ) ) {
				return array(
					'valid' => false,
					'error' => 'Prohibited path is not allowed in packages.',
				);
			}

			return array(
				'valid' => false,
				'error' => 'Path must specify at least a root directory and a filename segment.',
			);
		}

		$filename = end( $segments );

		// Check reserved metadata files on final segment.
		if ( in_array( strtolower( $filename ), self::RESERVED_FILENAMES, true ) ) {
			return array(
				'valid' => false,
				'error' => 'Reserved filename is prohibited.',
			);
		}

		// Check prohibited files on final segment.
		if ( in_array( strtolower( $filename ), self::PROHIBITED_NAMES, true ) ) {
			return array(
				'valid' => false,
				'error' => 'Prohibited path is not allowed in packages.',
			);
		}

		// Verify root prefix.
		$root = $segments[0];
		if ( ! in_array( $root, self::APPROVED_ROOTS, true ) ) {
			return array(
				'valid' => false,
				'error' => 'Unapproved root prefix in path. Allowed roots: database, uploads, assets.',
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
	 * @param bool|null            $has_intl Optional capability flag for intl extension.
	 * @param bool|null            $has_mbstring Optional capability flag for mbstring extension.
	 * @return array{valid: bool, error: string} Validation result.
	 */
	public static function validate_entry( array $entry, $has_intl = null, $has_mbstring = null ) {
		// Reject unknown extraneous keys.
		$allowed_keys = array( 'path', 'size', 'sha256', 'type' );
		foreach ( array_keys( $entry ) as $key ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				return array(
					'valid' => false,
					'error' => 'Extraneous entry key is prohibited in manifest entry.',
				);
			}
		}

		if ( ! isset( $entry['path'] ) || ! is_string( $entry['path'] ) ) {
			return array(
				'valid' => false,
				'error' => 'Entry missing string "path".',
			);
		}

		$path_res = self::validate_path( $entry['path'], $has_intl, $has_mbstring );
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

		// Enforce regular file type: reject symlink, hardlink, directory, or device.
		if ( isset( $entry['type'] ) ) {
			if ( 'regular' !== $entry['type'] ) {
				return array(
					'valid' => false,
					'error' => 'Non-regular entry type is prohibited. Only "regular" files allowed.',
				);
			}
		}

		return array(
			'valid' => true,
			'error' => '',
		);
	}
}
