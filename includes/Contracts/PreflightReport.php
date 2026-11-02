<?php
/**
 * Preflight Report Contract.
 *
 * Encapsulates the structured result of a read-only source discovery and
 * preflight validation run. Enforces fail-closed invariants so a report
 * never claims a migration is ready to execute when blocker findings exist
 * or when destination environment verification is unverified.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Contracts;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PreflightReport
 */
class PreflightReport implements \JsonSerializable {

	/**
	 * Report schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Status when caller lacks required WordPress capabilities.
	 *
	 * @var string
	 */
	const STATUS_UNAUTHORIZED = 'unauthorized';

	/**
	 * Status when one or more blocker findings prevent proceeding.
	 *
	 * @var string
	 */
	const STATUS_BLOCKED = 'blocked';

	/**
	 * Status when source inspection succeeded with zero blockers, while destination remains unverified.
	 *
	 * @var string
	 */
	const STATUS_SOURCE_INSPECTED = 'source_inspected';

	/**
	 * Requested migration mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Requested source site (blog) ID, if applicable.
	 *
	 * @var int|null
	 */
	private $source_site_id;

	/**
	 * Overall preflight status ('unauthorized', 'blocked', or 'source_inspected').
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Whether all preflight prerequisites (both source and destination) are verified to proceed.
	 *
	 * @var bool
	 */
	private $can_proceed_to_next_stage;

	/**
	 * Target environment expectation and verification metadata.
	 *
	 * @var array<string, mixed>
	 */
	private $target_expectations;

	/**
	 * Read-only source inventory array, or null when unauthorized/uncollected.
	 *
	 * @var array<string, mixed>|null
	 */
	private $source_inventory;

	/**
	 * List of preflight findings.
	 *
	 * @var PreflightFinding[]
	 */
	private $findings = array();

	/**
	 * Finding counts by severity.
	 *
	 * @var array<string, int>
	 */
	private $counts = array(
		PreflightFinding::SEVERITY_BLOCKER => 0,
		PreflightFinding::SEVERITY_WARNING => 0,
		PreflightFinding::SEVERITY_INFO    => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param string                    $mode                      Requested migration mode.
	 * @param int|null                  $source_site_id            Requested source site ID or null.
	 * @param string                    $status                    Report status constant.
	 * @param bool                      $can_proceed_to_next_stage Candidate proceed flag (enforced against invariants).
	 * @param array<string, mixed>      $target_expectations       Target expectations and verification status.
	 * @param array<string, mixed>|null $source_inventory          Read-only source inventory or null.
	 * @param PreflightFinding[]        $findings                  Array of PreflightFinding instances.
	 */
	public function __construct(
		$mode,
		$source_site_id,
		$status,
		$can_proceed_to_next_stage,
		array $target_expectations,
		$source_inventory,
		array $findings = array()
	) {
		$this->mode                = (string) $mode;
		$this->source_site_id      = null !== $source_site_id ? (int) $source_site_id : null;
		$this->status              = (string) $status;
		$this->target_expectations = $target_expectations;
		$this->source_inventory    = is_array( $source_inventory ) ? $source_inventory : null;

		foreach ( $findings as $finding ) {
			if ( $finding instanceof PreflightFinding ) {
				$this->findings[] = $finding;
				$sev              = $finding->get_severity();
				if ( isset( $this->counts[ $sev ] ) ) {
					++$this->counts[ $sev ];
				}
			}
		}

		// Fail-closed invariant: never allow proceeding if unauthorized, blocked, any blocker finding exists,
		// or target verification_status is not explicitly 'verified'.
		$target_verified = isset( $this->target_expectations['verification_status'] )
			&& 'verified' === $this->target_expectations['verification_status'];

		if (
			self::STATUS_UNAUTHORIZED === $this->status
			|| self::STATUS_BLOCKED === $this->status
			|| $this->counts[ PreflightFinding::SEVERITY_BLOCKER ] > 0
			|| ! $target_verified
		) {
			$this->can_proceed_to_next_stage = false;
		} else {
			$this->can_proceed_to_next_stage = (bool) $can_proceed_to_next_stage;
		}
	}

	/**
	 * Get the requested migration mode.
	 *
	 * @return string
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * Get the requested source site ID.
	 *
	 * @return int|null
	 */
	public function get_source_site_id() {
		return $this->source_site_id;
	}

	/**
	 * Get the report status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Determine whether the migration can proceed to the next stage.
	 *
	 * @return bool
	 */
	public function can_proceed_to_next_stage() {
		return $this->can_proceed_to_next_stage;
	}

	/**
	 * Get target expectations array.
	 *
	 * @return array<string, mixed>
	 */
	public function get_target_expectations() {
		return $this->target_expectations;
	}

	/**
	 * Get all preflight findings.
	 *
	 * @return PreflightFinding[]
	 */
	public function get_findings() {
		return $this->findings;
	}

	/**
	 * Get the source inventory array or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_source_inventory() {
		return $this->source_inventory;
	}

	/**
	 * Convert the report to an associative array suitable for JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		$serialized_findings = array();
		foreach ( $this->findings as $finding ) {
			$serialized_findings[] = $finding->to_array();
		}

		return array(
			'schema_version'            => self::SCHEMA_VERSION,
			'mode'                      => $this->mode,
			'source_site_id'            => $this->source_site_id,
			'status'                    => $this->status,
			'can_proceed_to_next_stage' => $this->can_proceed_to_next_stage,
			'target_expectations'       => $this->target_expectations,
			'counts'                    => $this->counts,
			'findings'                  => $serialized_findings,
			'source_inventory'          => $this->source_inventory,
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
