<?php
/**
 * Migration Mode Contract.
 *
 * Defines the canonical identifiers and topology/parameter requirements for
 * the four supported SiteConvoy migration modes.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Contracts;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MigrationMode
 */
class MigrationMode {

	/**
	 * Mode 1: Subsite -> Standalone WordPress.
	 *
	 * @var string
	 */
	const SUBSITE_TO_STANDALONE = 'subsite_to_standalone';

	/**
	 * Mode 2: Subsite -> Another Multisite Network.
	 *
	 * @var string
	 */
	const SUBSITE_TO_MULTISITE = 'subsite_to_multisite';

	/**
	 * Mode 3: Full Multisite Network -> New Environment.
	 *
	 * @var string
	 */
	const FULL_NETWORK_TO_NEW_ENV = 'full_network_to_new_env';

	/**
	 * Mode 4: Standalone WordPress -> Multisite Subsite.
	 *
	 * @var string
	 */
	const STANDALONE_TO_SUBSITE = 'standalone_to_subsite';

	/**
	 * Return all canonical migration mode identifiers.
	 *
	 * @return string[] List of supported migration mode identifiers.
	 */
	public static function all() {
		return array(
			self::SUBSITE_TO_STANDALONE,
			self::SUBSITE_TO_MULTISITE,
			self::FULL_NETWORK_TO_NEW_ENV,
			self::STANDALONE_TO_SUBSITE,
		);
	}

	/**
	 * Check whether a given mode identifier is valid.
	 *
	 * @param mixed $mode Candidate mode identifier.
	 * @return bool True when valid.
	 */
	public static function is_valid( $mode ) {
		if ( ! is_string( $mode ) || '' === $mode ) {
			return false;
		}
		return in_array( $mode, self::all(), true );
	}

	/**
	 * Determine whether the migration mode requires a WordPress multisite source environment.
	 *
	 * @param string $mode Migration mode identifier.
	 * @return bool True if source must be multisite; false if source must be standalone.
	 */
	public static function requires_multisite_source( $mode ) {
		return in_array(
			$mode,
			array(
				self::SUBSITE_TO_STANDALONE,
				self::SUBSITE_TO_MULTISITE,
				self::FULL_NETWORK_TO_NEW_ENV,
			),
			true
		);
	}

	/**
	 * Determine whether the migration mode requires an explicit source site (blog) ID.
	 *
	 * @param string $mode Migration mode identifier.
	 * @return bool True if an explicit source site ID is required.
	 */
	public static function requires_source_site_id( $mode ) {
		return in_array(
			$mode,
			array(
				self::SUBSITE_TO_STANDALONE,
				self::SUBSITE_TO_MULTISITE,
			),
			true
		);
	}

	/**
	 * Return the expected target environment topology type for a migration mode.
	 *
	 * @param string $mode Migration mode identifier.
	 * @return string Expected target type ('standalone', 'multisite_subsite', 'multisite_network', or 'unknown').
	 */
	public static function expected_target_type( $mode ) {
		switch ( $mode ) {
			case self::SUBSITE_TO_STANDALONE:
				return 'standalone';
			case self::SUBSITE_TO_MULTISITE:
			case self::STANDALONE_TO_SUBSITE:
				return 'multisite_subsite';
			case self::FULL_NETWORK_TO_NEW_ENV:
				return 'multisite_network';
			default:
				return 'unknown';
		}
	}

	/**
	 * Strictly validate a supplied source site (blog) ID before integer conversion.
	 *
	 * Accepts only `null`, a positive `int`, or a canonical positive decimal digit string
	 * (`^[1-9][0-9]*$`). Rejects non-integer strings (including numeric prefixes like `'1abc'`,
	 * leading zeros like `'01'`, or whitespace), floats/fractions, booleans, arrays, objects,
	 * zero, and negative numbers without coercing them into a blog ID.
	 *
	 * @param mixed $raw_site_id Raw candidate source site ID.
	 * @return array{valid: bool, site_id: int|null, reason: string} Validation result.
	 */
	public static function validate_source_site_id( $raw_site_id ) {
		if ( null === $raw_site_id ) {
			return array(
				'valid'   => true,
				'site_id' => null,
				'reason'  => '',
			);
		}

		if ( is_int( $raw_site_id ) ) {
			if ( $raw_site_id > 0 ) {
				return array(
					'valid'   => true,
					'site_id' => $raw_site_id,
					'reason'  => '',
				);
			}

			return array(
				'valid'   => false,
				'site_id' => null,
				'reason'  => 'Source site ID must be a positive integer greater than zero.',
			);
		}

		if ( is_string( $raw_site_id ) && 1 === preg_match( '/^[1-9][0-9]*$/', $raw_site_id ) ) {
			$int_val = (int) $raw_site_id;
			if ( (string) $int_val === $raw_site_id && $int_val > 0 ) {
				return array(
					'valid'   => true,
					'site_id' => $int_val,
					'reason'  => '',
				);
			}
		}

		return array(
			'valid'   => false,
			'site_id' => null,
			'reason'  => 'Source site ID must be a positive integer; non-integer strings, fractions, and booleans are not allowed.',
		);
	}
}
