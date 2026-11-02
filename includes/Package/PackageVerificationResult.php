<?php
/**
 * Package Verification Result Value Object.
 *
 * Implements result contract for stream integrity verification.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

use JsonSerializable;

/**
 * Class PackageVerificationResult
 */
class PackageVerificationResult implements JsonSerializable {

	/**
	 * Overall success status.
	 *
	 * @var bool
	 */
	private $is_success;

	/**
	 * Verified entries count.
	 *
	 * @var int
	 */
	private $verified_count;

	/**
	 * Structured errors encountered.
	 *
	 * @var array<int, array{code: string, path: string, message: string}>
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param bool                                                           $is_success      Success flag.
	 * @param int                                                            $verified_count  Verified entries count.
	 * @param array<int, array{code: string, path: string, message: string}> $errors Errors list.
	 */
	public function __construct( $is_success, $verified_count, array $errors = array() ) {
		$this->is_success     = (bool) $is_success;
		$this->verified_count = (int) $verified_count;
		$this->errors         = $errors;
	}

	/**
	 * Is verification successful.
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->is_success;
	}

	/**
	 * Get verified entry count.
	 *
	 * @return int
	 */
	public function get_verified_count() {
		return $this->verified_count;
	}

	/**
	 * Get error records.
	 *
	 * @return array<int, array{code: string, path: string, message: string}>
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Convert to array for JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'is_success'     => $this->is_success,
			'verified_count' => $this->verified_count,
			'errors'         => $this->errors,
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
