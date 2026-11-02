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
	 * @param string                           $mode    Migration mode.
	 * @param array<string, mixed>             $source  Source metadata.
	 * @param array<int, array<string, mixed>> $entries List of package entries.
	 *
	 * @throws InvalidArgumentException When arguments are invalid.
	 */
	public function __construct( $mode, array $source, array $entries ) {
		if ( ! MigrationMode::is_valid( $mode ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( sprintf( 'Invalid migration mode "%s".', $mode ) );
		}

		if ( ! isset( $source['is_multisite'] ) || ! is_bool( $source['is_multisite'] ) ) {
			throw new InvalidArgumentException( 'Source metadata must define boolean "is_multisite".' );
		}

		$this->mode   = $mode;
		$this->source = $source;

		// Validate and normalize entries.
		$normalized_entries = array();
		$seen_paths         = array();
		$seen_lower_paths   = array();

		foreach ( $entries as $entry ) {
			$validation = PackageManifestValidator::validate_entry( $entry );
			if ( ! $validation['valid'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( sprintf( 'Invalid manifest entry: %s', $validation['error'] ) );
			}

			$path       = $entry['path'];
			$lower_path = strtolower( $path );

			if ( isset( $seen_paths[ $path ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( sprintf( 'Duplicate entry path "%s".', $path ) );
			}

			if ( isset( $seen_lower_paths[ $lower_path ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new InvalidArgumentException( sprintf( 'Case-collision duplicate entry path "%s" vs "%s".', $path, $seen_lower_paths[ $lower_path ] ) );
			}

			$seen_paths[ $path ]             = true;
			$seen_lower_paths[ $lower_path ] = $path;

			$normalized_entries[] = array(
				'path'   => $path,
				'size'   => (int) $entry['size'],
				'sha256' => strtolower( (string) $entry['sha256'] ),
			);
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
