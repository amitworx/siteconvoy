<?php
/**
 * Package Archive Staging Result.
 *
 * Immutable value object representing the outcome of a package archive staging operation.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Package;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PackageArchiveStagingResult
 */
class PackageArchiveStagingResult {

	/**
	 * Whether staging succeeded.
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Absolute path to the exclusive staging directory created for the operation.
	 *
	 * @var string
	 */
	private $staging_root;

	/**
	 * Validated package manifest.
	 *
	 * @var PackageManifest|null
	 */
	private $manifest;

	/**
	 * Number of staged payload entries.
	 *
	 * @var int
	 */
	private $staged_count;

	/**
	 * Total bytes of staged payload files.
	 *
	 * @var int
	 */
	private $staged_bytes;

	/**
	 * Archive SHA-256 digest.
	 *
	 * @var string
	 */
	private $archive_sha256;

	/**
	 * Ledger mapping relative payload path to absolute staged file path.
	 *
	 * @var array<string, string>
	 */
	private $staged_ledger;

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
	 * Safe recovery locator for manual cleanup when automated cleanup cannot be verified.
	 *
	 * @var string
	 */
	private $recovery_locator;

	/**
	 * Constructor.
	 *
	 * @param bool                  $success          Success flag.
	 * @param string                $staging_root     Absolute path to exclusive staging directory.
	 * @param PackageManifest|null  $manifest         Validated package manifest.
	 * @param int                   $staged_count     Number of staged payload files.
	 * @param int                   $staged_bytes     Total bytes written.
	 * @param string                $archive_sha256   Archive SHA-256 checksum.
	 * @param array<string, string> $staged_ledger    Relative path to absolute path ledger.
	 * @param string                $error_code       Error code.
	 * @param string                $error_message    Error message.
	 * @param string                $recovery_locator Recovery locator for manual recovery.
	 */
	public function __construct(
		$success,
		$staging_root = '',
		$manifest = null,
		$staged_count = 0,
		$staged_bytes = 0,
		$archive_sha256 = '',
		array $staged_ledger = array(),
		$error_code = '',
		$error_message = '',
		$recovery_locator = ''
	) {
		$this->success          = (bool) $success;
		$this->staging_root     = (string) $staging_root;
		$this->manifest         = $manifest;
		$this->staged_count     = (int) $staged_count;
		$this->staged_bytes     = (int) $staged_bytes;
		$this->archive_sha256   = (string) $archive_sha256;
		$this->staged_ledger    = $staged_ledger;
		$this->error_code       = (string) $error_code;
		$this->error_message    = (string) $error_message;
		$this->recovery_locator = (string) $recovery_locator;
	}

	/**
	 * Create a successful staging result.
	 *
	 * @param string                $staging_root   Exclusive staging directory path.
	 * @param PackageManifest       $manifest       Validated manifest.
	 * @param int                   $staged_count   Count of staged files.
	 * @param int                   $staged_bytes   Total bytes.
	 * @param string                $archive_sha256 Verified archive SHA-256.
	 * @param array<string, string> $staged_ledger  File path ledger.
	 * @return self
	 */
	public static function success( $staging_root, PackageManifest $manifest, $staged_count, $staged_bytes, $archive_sha256, array $staged_ledger ) {
		return new self(
			true,
			$staging_root,
			$manifest,
			$staged_count,
			$staged_bytes,
			$archive_sha256,
			$staged_ledger,
			'',
			'',
			''
		);
	}

	/**
	 * Create a failed staging result.
	 *
	 * @param string $error_code       Sanitized error code.
	 * @param string $error_message    Sanitized error message.
	 * @param string $recovery_locator Safe locator for manual recovery.
	 * @return self
	 */
	public static function failure( $error_code, $error_message, $recovery_locator = '' ) {
		return new self(
			false,
			'',
			null,
			0,
			0,
			'',
			array(),
			$error_code,
			$error_message,
			$recovery_locator
		);
	}

	/**
	 * Whether staging succeeded.
	 *
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * Get exclusive staging root directory path.
	 *
	 * @return string
	 */
	public function get_staging_root() {
		return $this->staging_root;
	}

	/**
	 * Get validated package manifest.
	 *
	 * @return PackageManifest|null
	 */
	public function get_manifest() {
		return $this->manifest;
	}

	/**
	 * Get staged payload file count.
	 *
	 * @return int
	 */
	public function get_staged_count() {
		return $this->staged_count;
	}

	/**
	 * Get total staged payload bytes.
	 *
	 * @return int
	 */
	public function get_staged_bytes() {
		return $this->staged_bytes;
	}

	/**
	 * Get verified archive SHA-256 digest.
	 *
	 * @return string
	 */
	public function get_archive_sha256() {
		return $this->archive_sha256;
	}

	/**
	 * Get relative-to-staged path ledger.
	 *
	 * @return array<string, string>
	 */
	public function get_staged_ledger() {
		return $this->staged_ledger;
	}

	/**
	 * Get error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * Get error message.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->error_message;
	}

	/**
	 * Get safe manual recovery locator.
	 *
	 * @return string
	 */
	public function get_recovery_locator() {
		return $this->recovery_locator;
	}
}
