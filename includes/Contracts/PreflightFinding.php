<?php
/**
 * Preflight Finding Contract.
 *
 * Represents a single deterministic preflight check finding with a stable
 * machine-readable identifier, severity level, human-readable summary, and
 * structured non-sensitive context metadata.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Contracts;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PreflightFinding
 */
class PreflightFinding implements \JsonSerializable {

	/**
	 * Informational finding severity.
	 *
	 * @var string
	 */
	const SEVERITY_INFO = 'info';

	/**
	 * Warning finding severity (operator review required, non-fatal at source discovery stage).
	 *
	 * @var string
	 */
	const SEVERITY_WARNING = 'warning';

	/**
	 * Blocker finding severity (operation cannot proceed).
	 *
	 * @var string
	 */
	const SEVERITY_BLOCKER = 'blocker';

	/**
	 * Stable machine-readable finding code (e.g., 'SC_CUSTOM_USER_TABLE_UNSUPPORTED').
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Finding severity ('info', 'warning', or 'blocker').
	 *
	 * @var string
	 */
	private $severity;

	/**
	 * Human-readable explanation of the finding.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * Structured non-sensitive metadata associated with the finding.
	 *
	 * @var array<string, mixed>
	 */
	private $context;

	/**
	 * Constructor.
	 *
	 * @param string               $id       Stable machine-readable finding ID.
	 * @param string               $severity Severity level ('info', 'warning', 'blocker').
	 * @param string               $message  Human-readable summary.
	 * @param array<string, mixed> $context  Optional structured context.
	 * @throws \InvalidArgumentException When ID or severity is invalid.
	 */
	public function __construct( $id, $severity, $message, array $context = array() ) {
		if ( ! is_string( $id ) || '' === trim( $id ) ) {
			throw new \InvalidArgumentException( 'PreflightFinding ID must be a non-empty string.' );
		}

		$valid_severities = array(
			self::SEVERITY_INFO,
			self::SEVERITY_WARNING,
			self::SEVERITY_BLOCKER,
		);

		if ( ! in_array( $severity, $valid_severities, true ) ) {
			throw new \InvalidArgumentException( 'Invalid PreflightFinding severity level.' );
		}

		$this->id       = $id;
		$this->severity = $severity;
		$this->message  = (string) $message;
		$this->context  = $context;
	}

	/**
	 * Get the machine-readable finding ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get the finding severity.
	 *
	 * @return string
	 */
	public function get_severity() {
		return $this->severity;
	}

	/**
	 * Get the human-readable message.
	 *
	 * @return string
	 */
	public function get_message() {
		return $this->message;
	}

	/**
	 * Get the finding context array.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * Check whether this finding is a blocker.
	 *
	 * @return bool
	 */
	public function is_blocker() {
		return self::SEVERITY_BLOCKER === $this->severity;
	}

	/**
	 * Convert the finding to an associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'id'       => $this->id,
			'severity' => $this->severity,
			'message'  => $this->message,
			'context'  => $this->context,
		);
	}

	/**
	 * Specify data which should be serialized to JSON.
	 *
	 * @return array<string, mixed>
	 */
	#[\ReturnTypeWillChange]
	public function jsonSerialize() {
		return $this->to_array();
	}
}
