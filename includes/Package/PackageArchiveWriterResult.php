<?php
/**
 * Package Archive Writer Result.
 *
 * Immutable value object representing the outcome of a package archive write operation.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PackageArchiveWriterResult
 */
class PackageArchiveWriterResult {

	/**
	 * Whether the archive write and verification succeeded.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Path to the published archive file (empty on failure).
	 *
	 * @var string
	 */
	private $archive_path;

	/**
	 * Number of payload members written and verified.
	 *
	 * @var int
	 */
	private $member_count;

	/**
	 * Total byte size of the published archive file.
	 *
	 * @var int
	 */
	private $archive_size;

	/**
	 * SHA-256 checksum of the published archive file.
	 *
	 * @var string
	 */
	private $archive_sha256;

	/**
	 * List of errors encountered during writing or verification.
	 *
	 * @var array<int, array{code: string, message: string}>
	 */
	private $errors;

	/**
	 * Constructor.
	 *
	 * @param bool                                             $success        Success flag.
	 * @param string                                           $archive_path   Path to archive.
	 * @param int                                              $member_count   Number of members.
	 * @param int                                              $archive_size   Archive file size in bytes.
	 * @param string                                           $archive_sha256 Archive file SHA-256.
	 * @param array<int, array{code: string, message: string}> $errors         Errors array.
	 */
	public function __construct( $success, $archive_path = '', $member_count = 0, $archive_size = 0, $archive_sha256 = '', array $errors = array() ) {
		$this->success        = (bool) $success;
		$this->archive_path   = (string) $archive_path;
		$this->member_count   = (int) $member_count;
		$this->archive_size   = (int) $archive_size;
		$this->archive_sha256 = (string) $archive_sha256;
		$this->errors         = $errors;
	}

	/**
	 * Create a failure result.
	 *
	 * @param string $code    Error code.
	 * @param string $message Sanitized error message.
	 * @return self
	 */
	public static function failure( $code, $message ) {
		return new self(
			false,
			'',
			0,
			0,
			'',
			array(
				array(
					'code'    => (string) $code,
					'message' => (string) $message,
				),
			)
		);
	}

	/**
	 * Check if write operation succeeded.
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * Get published archive path.
	 *
	 * @return string
	 */
	public function get_archive_path() {
		return $this->archive_path;
	}

	/**
	 * Get verified payload member count.
	 *
	 * @return int
	 */
	public function get_member_count() {
		return $this->member_count;
	}

	/**
	 * Get published archive byte size.
	 *
	 * @return int
	 */
	public function get_archive_size() {
		return $this->archive_size;
	}

	/**
	 * Get published archive SHA-256 checksum.
	 *
	 * @return string
	 */
	public function get_archive_sha256() {
		return $this->archive_sha256;
	}

	/**
	 * Get list of errors.
	 *
	 * @return array<int, array{code: string, message: string}>
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * Serialize to array for logging/reporting.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'success'        => $this->success,
			'archive_path'   => $this->archive_path,
			'member_count'   => $this->member_count,
			'archive_size'   => $this->archive_size,
			'archive_sha256' => $this->archive_sha256,
			'errors'         => $this->errors,
		);
	}
}
