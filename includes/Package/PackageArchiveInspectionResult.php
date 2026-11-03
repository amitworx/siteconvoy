<?php
/**
 * Package Archive Inspection Result.
 *
 * Immutable value object representing the outcome of a package archive inspection operation.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PackageArchiveInspectionResult
 */
class PackageArchiveInspectionResult {

	/**
	 * Whether the archive inspection and verification succeeded.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Validated package manifest (null on failure).
	 *
	 * @var PackageManifest|null
	 */
	private $manifest;

	/**
	 * Number of payload members verified in the archive.
	 *
	 * @var int
	 */
	private $member_count;

	/**
	 * Total byte size of the archive file.
	 *
	 * @var int
	 */
	private $archive_size;

	/**
	 * SHA-256 checksum of the archive file.
	 *
	 * @var string
	 */
	private $archive_sha256;

	/**
	 * Error code on failure.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Error message on failure.
	 *
	 * @var string
	 */
	private $error_message;

	/**
	 * Constructor.
	 *
	 * @param bool                 $success        Success flag.
	 * @param PackageManifest|null $manifest       Validated package manifest.
	 * @param int                  $member_count   Number of payload members.
	 * @param int                  $archive_size   Archive size in bytes.
	 * @param string               $archive_sha256 Archive SHA-256 checksum.
	 * @param string               $error_code     Error code.
	 * @param string               $error_message  Error message.
	 */
	public function __construct(
		$success,
		$manifest = null,
		$member_count = 0,
		$archive_size = 0,
		$archive_sha256 = '',
		$error_code = '',
		$error_message = ''
	) {
		$this->success        = (bool) $success;
		$this->manifest       = $manifest;
		$this->member_count   = (int) $member_count;
		$this->archive_size   = (int) $archive_size;
		$this->archive_sha256 = (string) $archive_sha256;
		$this->error_code     = (string) $error_code;
		$this->error_message  = (string) $error_message;
	}

	/**
	 * Factory method for a successful inspection.
	 *
	 * @param PackageManifest $manifest       Validated package manifest.
	 * @param int             $member_count   Number of verified payload members.
	 * @param int             $archive_size   Total archive byte size.
	 * @param string          $archive_sha256 Total archive SHA-256 digest.
	 * @return self Success result.
	 */
	public static function success( PackageManifest $manifest, $member_count, $archive_size, $archive_sha256 ) {
		return new self(
			true,
			$manifest,
			$member_count,
			$archive_size,
			$archive_sha256,
			'',
			''
		);
	}

	/**
	 * Factory method for a failed inspection.
	 *
	 * @param string $code    Sanitized error code.
	 * @param string $message Sanitized error message.
	 * @return self Failure result.
	 */
	public static function failure( $code, $message ) {
		return new self(
			false,
			null,
			0,
			0,
			'',
			$code,
			$message
		);
	}

	/**
	 * Check if inspection was successful.
	 *
	 * @return bool True if valid, false otherwise.
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * Get the validated manifest.
	 *
	 * @return PackageManifest|null Validated manifest on success, null on failure.
	 */
	public function get_manifest() {
		return $this->manifest;
	}

	/**
	 * Get verified payload member count.
	 *
	 * @return int Member count.
	 */
	public function get_member_count() {
		return $this->member_count;
	}

	/**
	 * Get total archive byte size.
	 *
	 * @return int Archive size in bytes.
	 */
	public function get_archive_size() {
		return $this->archive_size;
	}

	/**
	 * Get total archive SHA-256 checksum.
	 *
	 * @return string SHA-256 digest.
	 */
	public function get_archive_sha256() {
		return $this->archive_sha256;
	}

	/**
	 * Get error code.
	 *
	 * @return string Error code on failure, or empty string.
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Get error message.
	 *
	 * @return string Error message on failure, or empty string.
	 */
	public function get_error_message() {
		return $this->error_message;
	}
}
