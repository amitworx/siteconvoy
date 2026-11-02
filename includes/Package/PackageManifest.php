<?php
/**
 * Package Manifest Value Object.
 *
 * Implements deterministic package manifest contract for SiteConvoy migration packages.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use JsonSerializable;
use InvalidArgumentException;
use SiteConvoy\Contracts\MigrationMode;

/**
 * Class PackageManifest
 */
class PackageManifest implements JsonSerializable {

	/**
	 * Canonical package manifest format version.
	 *
	 * @var string
	 */
	const FORMAT_VERSION = '1.0';

	/**
	 * Canonical migration mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Normalized source environment metadata.
	 *
	 * @var array<string, mixed>
	 */
	private $source;

	/**
	 * Canonical sorted list of payload entries.
	 *
	 * @var array<int, array{path: string, size: int, sha256: string}>
	 */
	private $entries;

	/**
	 * Constructor.
	 *
	 * @param string                           $mode         Migration mode.
	 * @param array<string, mixed>             $source       Source metadata.
	 * @param array<int, array<string, mixed>> $entries      List of package entries.
	 * @param bool|null                        $has_mbstring Optional capability flag for mbstring (defaults to runtime check).
	 * @param bool|null                        $has_intl     Optional capability flag for intl (defaults to runtime check).
	 *
	 * @throws InvalidArgumentException When arguments are invalid.
	 */
	public function __construct( $mode, array $source, array $entries, $has_mbstring = null, $has_intl = null ) {
		if ( ! MigrationMode::is_valid( $mode ) ) {
			throw new InvalidArgumentException( 'Invalid migration mode specified.' );
		}

		$this->mode   = $mode;
		$this->source = self::validate_and_normalize_source( $mode, $source );

		$runtime_intl     = function_exists( 'normalizer_is_normalized' ) && defined( 'Normalizer::FORM_C' );
		$runtime_mbstring = function_exists( 'mb_convert_case' ) && defined( 'MB_CASE_FOLD' ) && function_exists( 'mb_check_encoding' );

		// Capability overrides can only disable/simulate missing support (false), never force/elevate support (true).
		$has_intl     = $runtime_intl && ( null === $has_intl || false !== $has_intl );
		$has_mbstring = $runtime_mbstring && ( null === $has_mbstring || false !== $has_mbstring );

		// Validate and normalize entries.
		$normalized_entries = array();
		$seen_paths         = array();
		$seen_lower_paths   = array();

		foreach ( $entries as $entry ) {
			$validation = PackageManifestValidator::validate_entry( $entry, $has_intl, $has_mbstring );
			if ( ! $validation['valid'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( sprintf( 'Invalid manifest entry: %s', $validation['error'] ) );
			}

			$path     = $entry['path'];
			$is_ascii = ( 0 === preg_match( '/[^\x20-\x7E]/', $path ) );

			if ( $has_mbstring ) {
				$lower_path = mb_convert_case( $path, MB_CASE_FOLD, 'UTF-8' );
			} elseif ( ! $is_ascii ) {
				throw new InvalidArgumentException( 'Non-ASCII paths require the PHP mbstring extension for Unicode case-folding safety.' );
			} else {
				$lower_path = strtolower( $path );
			}

			if ( isset( $seen_paths[ $path ] ) ) {
				throw new InvalidArgumentException( 'Duplicate entry path detected in manifest.' );
			}

			if ( isset( $seen_lower_paths[ $lower_path ] ) ) {
				throw new InvalidArgumentException( 'Case-collision duplicate entry path detected.' );
			}

			$seen_paths[ $path ]             = true;
			$seen_lower_paths[ $lower_path ] = $path;

			$normalized_entry = array(
				'path'   => $path,
				'size'   => (int) $entry['size'],
				'sha256' => strtolower( (string) $entry['sha256'] ),
			);
			if ( isset( $entry['type'] ) ) {
				$normalized_entry['type'] = (string) $entry['type'];
			}

			$normalized_entries[] = $normalized_entry;
		}

		// Sort deterministically by canonical path.
		usort(
			$normalized_entries,
			function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);

		$this->entries = $normalized_entries;
	}

	/**
	 * Validate and normalize source topology metadata.
	 *
	 * @param string               $mode   Migration mode.
	 * @param array<string, mixed> $source Raw source metadata.
	 * @return array<string, mixed> Normalized source metadata.
	 * @throws InvalidArgumentException When source metadata is invalid.
	 */
	public static function validate_and_normalize_source( $mode, array $source ) {
		$allowed_keys = array( 'is_multisite', 'network_id', 'site_id', 'site_count', 'base_prefix' );
		foreach ( array_keys( $source ) as $key ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				throw new InvalidArgumentException( 'Unexpected key in source topology metadata.' );
			}
		}

		if ( ! isset( $source['is_multisite'] ) || ! is_bool( $source['is_multisite'] ) ) {
			throw new InvalidArgumentException( 'Source metadata must define boolean "is_multisite".' );
		}

		$is_multisite = $source['is_multisite'];
		$network_id   = array_key_exists( 'network_id', $source ) ? $source['network_id'] : null;
		$site_id      = array_key_exists( 'site_id', $source ) ? $source['site_id'] : null;
		$site_count   = array_key_exists( 'site_count', $source ) ? $source['site_count'] : null;
		$base_prefix  = array_key_exists( 'base_prefix', $source ) ? $source['base_prefix'] : null;

		if ( null !== $network_id && ( ! is_int( $network_id ) || $network_id <= 0 ) ) {
			throw new InvalidArgumentException( 'Source network_id must be a positive integer or null.' );
		}

		if ( null !== $site_id && ( ! is_int( $site_id ) || $site_id <= 0 ) ) {
			throw new InvalidArgumentException( 'Source site_id must be a positive integer or null.' );
		}

		if ( null !== $site_count && ( ! is_int( $site_count ) || $site_count <= 0 ) ) {
			throw new InvalidArgumentException( 'Source site_count must be a positive integer or null.' );
		}

		if ( null !== $base_prefix ) {
			if ( ! is_string( $base_prefix ) || '' === $base_prefix ) {
				throw new InvalidArgumentException( 'Source base_prefix must be a non-empty string.' );
			}
			// Enforce WordPress table prefix syntax: conservative alphanumeric and underscore grammar.
			if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $base_prefix ) ) {
				throw new InvalidArgumentException( 'Source base_prefix contains invalid characters; only alphanumeric characters and underscores are permitted.' );
			}
		}

		// Enforce mode-specific topology invariants.
		switch ( $mode ) {
			case MigrationMode::SUBSITE_TO_STANDALONE:
			case MigrationMode::SUBSITE_TO_MULTISITE:
				if ( ! $is_multisite ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new InvalidArgumentException( sprintf( 'Mode "%s" requires source is_multisite=true.', $mode ) );
				}
				if ( ! is_int( $network_id ) || $network_id <= 0 ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new InvalidArgumentException( sprintf( 'Mode "%s" requires positive integer source network_id.', $mode ) );
				}
				if ( ! is_int( $site_id ) || $site_id <= 0 ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					throw new InvalidArgumentException( sprintf( 'Mode "%s" requires positive integer source site_id.', $mode ) );
				}
				break;

			case MigrationMode::FULL_NETWORK_TO_NEW_ENV:
				if ( ! $is_multisite ) {
					throw new InvalidArgumentException( 'Mode full_network_to_new_env requires source is_multisite=true.' );
				}
				if ( ! is_int( $network_id ) || $network_id <= 0 ) {
					throw new InvalidArgumentException( 'Mode full_network_to_new_env requires positive integer source network_id.' );
				}
				if ( null !== $site_id ) {
					throw new InvalidArgumentException( 'Mode full_network_to_new_env must not specify a single source site_id.' );
				}
				break;

			case MigrationMode::STANDALONE_TO_SUBSITE:
				if ( $is_multisite ) {
					throw new InvalidArgumentException( 'Mode standalone_to_subsite requires source is_multisite=false.' );
				}
				if ( null !== $network_id ) {
					throw new InvalidArgumentException( 'Mode standalone_to_subsite must not specify network_id.' );
				}
				if ( ! is_int( $site_id ) || $site_id <= 0 ) {
					throw new InvalidArgumentException( 'Mode standalone_to_subsite requires positive integer source site_id (typically 1).' );
				}
				break;
		}

		$normalized = array(
			'is_multisite' => $is_multisite,
		);
		if ( array_key_exists( 'network_id', $source ) ) {
			$normalized['network_id'] = $network_id;
		}
		if ( array_key_exists( 'site_id', $source ) ) {
			$normalized['site_id'] = $site_id;
		}
		if ( array_key_exists( 'site_count', $source ) ) {
			$normalized['site_count'] = $site_count;
		}
		if ( array_key_exists( 'base_prefix', $source ) ) {
			$normalized['base_prefix'] = $base_prefix;
		}

		return $normalized;
	}

	/**
	 * Instantiate and strictly validate PackageManifest from untrusted array data.
	 *
	 * @param array<string, mixed> $data Raw untrusted manifest data.
	 * @return self Validated PackageManifest instance.
	 * @throws InvalidArgumentException When data is invalid or format_version is unsupported.
	 */
	public static function from_array( array $data ) {
		$allowed_top_keys = array( 'format_version', 'mode', 'source', 'entries' );
		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, $allowed_top_keys, true ) ) {
				throw new InvalidArgumentException( 'Unexpected top-level property in manifest.' );
			}
		}

		foreach ( $allowed_top_keys as $required_key ) {
			if ( ! array_key_exists( $required_key, $data ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( sprintf( 'Missing required top-level property "%s" in manifest.', $required_key ) );
			}
		}

		if ( ! is_string( $data['format_version'] ) || self::FORMAT_VERSION !== $data['format_version'] ) {
			throw new InvalidArgumentException( 'Unsupported format_version in manifest. Only version 1.0 is supported.' );
		}

		if ( ! is_string( $data['mode'] ) ) {
			throw new InvalidArgumentException( 'Manifest property "mode" must be a string.' );
		}

		if ( ! is_array( $data['source'] ) ) {
			throw new InvalidArgumentException( 'Manifest property "source" must be an associative array.' );
		}

		if ( ! is_array( $data['entries'] ) ) {
			throw new InvalidArgumentException( 'Manifest property "entries" must be an array of entry objects.' );
		}

		return new self( $data['mode'], $data['source'], $data['entries'] );
	}

	/**
	 * Get format version.
	 *
	 * @return string
	 */
	public function get_format_version() {
		return self::FORMAT_VERSION;
	}

	/**
	 * Get migration mode.
	 *
	 * @return string
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * Get source topology metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_source() {
		return $this->source;
	}

	/**
	 * Get entries.
	 *
	 * @return array<int, array{path: string, size: int, sha256: string}>
	 */
	public function get_entries() {
		return $this->entries;
	}

	/**
	 * Convert manifest to associative array for JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'format_version' => self::FORMAT_VERSION,
			'mode'           => $this->mode,
			'source'         => $this->source,
			'entries'        => $this->entries,
		);
	}

	/**
	 * JSON serialization handler.
	 *
	 * @return array<string, mixed>
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->to_array();
	}
}
