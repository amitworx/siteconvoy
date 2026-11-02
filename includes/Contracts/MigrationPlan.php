<?php
/**
 * Migration Plan Contract.
 *
 * Encapsulates the deterministic, read-only migration scope preview plan for
 * any of the four SiteConvoy migration directions. Enforces fail-closed invariants
 * so that a plan is never represented as an executable migration.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Contracts;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MigrationPlan
 */
class MigrationPlan implements \JsonSerializable {

	/**
	 * Plan schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Requested canonical migration mode.
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Source site ID (or null for full network / standalone modes).
	 *
	 * @var int|null
	 */
	private $source_site_id;

	/**
	 * Plan status: 'unauthorized', 'blocked', 'needs_review', or 'draft'.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Scoped database table breakdown.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private $tables;

	/**
	 * Discovered metadata-only dependencies.
	 *
	 * @var array<string, mixed>
	 */
	private $dependencies;

	/**
	 * Associated preflight report.
	 *
	 * @var PreflightReport|null
	 */
	private $preflight_report;

	/**
	 * Source environment topology metadata.
	 *
	 * @var array<string, mixed>
	 */
	private $source_topology;

	/**
	 * Constructor.
	 *
	 * @param string               $mode             Canonical migration mode.
	 * @param int|null             $source_site_id   Source site ID or null.
	 * @param string               $status           Plan status ('draft', 'needs_review', 'blocked', 'unauthorized').
	 * @param array<string, mixed> $tables           Categorized tables (blog, global, ms_global, unresolved).
	 * @param array<string, mixed> $dependencies     Metadata-only dependencies.
	 * @param PreflightReport|null $preflight_report Associated preflight report.
	 * @param array<string, mixed> $source_topology  Source environment topology.
	 */
	public function __construct(
		$mode,
		$source_site_id,
		$status,
		array $tables,
		array $dependencies,
		$preflight_report = null,
		array $source_topology = array()
	) {
		$this->mode             = (string) $mode;
		$this->source_site_id   = null !== $source_site_id ? (int) $source_site_id : null;
		$this->tables           = $tables;
		$this->dependencies     = $dependencies;
		$this->preflight_report = $preflight_report instanceof PreflightReport ? $preflight_report : null;
		$this->source_topology  = $source_topology;

		// Fail-closed invariant: if preflight report is unauthorized or blocked, force plan status.
		if ( $this->preflight_report ) {
			if ( PreflightReport::STATUS_UNAUTHORIZED === $this->preflight_report->get_status() ) {
				$this->status = 'unauthorized';
				$this->tables = array();
			} elseif ( PreflightReport::STATUS_BLOCKED === $this->preflight_report->get_status() ) {
				$this->status = 'blocked';
			} else {
				$this->status = (string) $status;
			}
		} else {
			$this->status = (string) $status;
		}
	}

	/**
	 * Get the schema version.
	 *
	 * @return string
	 */
	public function get_schema_version() {
		return self::SCHEMA_VERSION;
	}

	/**
	 * Get the migration mode.
	 *
	 * @return string
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * Get the source site ID or null.
	 *
	 * @return int|null
	 */
	public function get_source_site_id() {
		return $this->source_site_id;
	}

	/**
	 * Get the overall plan status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Whether this plan is executable.
	 *
	 * Fail-closed invariant: at the planning/inspection stage, a preview plan is
	 * NEVER executable. It requires destination verification, user-collision review,
	 * pre-migration backup checkpointing, and explicit operator authorization in M2+.
	 *
	 * @return bool Always false.
	 */
	public function is_executable() {
		return false;
	}

	/**
	 * Get the scoped tables list.
	 *
	 * @return array<string, mixed>
	 */
	public function get_tables() {
		return $this->tables;
	}

	/**
	 * Get the dependencies list.
	 *
	 * @return array<string, mixed>
	 */
	public function get_dependencies() {
		return $this->dependencies;
	}

	/**
	 * Get the associated preflight report.
	 *
	 * @return PreflightReport|null
	 */
	public function get_preflight_report() {
		return $this->preflight_report;
	}

	/**
	 * Get source topology metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_source_topology() {
		return $this->source_topology;
	}

	/**
	 * Get list of outstanding verifications that must occur before execution in M2+.
	 *
	 * @return array<int, string>
	 */
	public function get_outstanding_verifications() {
		return array(
			'destination_environment_connectivity_and_compatibility',
			'user_identity_collision_and_role_mapping_approval',
			'unresolved_custom_and_plugin_table_disposition',
			'pre_migration_backup_checkpoint_creation',
			'operator_explicit_execution_approval',
		);
	}

	/**
	 * Convert the plan to an associative array suitable for JSON serialization.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() {
		return array(
			'schema_version'            => self::SCHEMA_VERSION,
			'mode'                      => $this->mode,
			'source_site_id'            => $this->source_site_id,
			'status'                    => $this->status,
			'is_executable'             => false,
			'source_topology'           => ! empty( $this->source_topology ) ? $this->source_topology : (object) array(),
			'tables'                    => ! empty( $this->tables ) ? $this->tables : (object) array(),
			'dependencies'              => ! empty( $this->dependencies ) ? $this->dependencies : (object) array(),
			'outstanding_verifications' => $this->get_outstanding_verifications(),
			'preflight'                 => $this->preflight_report ? $this->preflight_report->to_array() : null,
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
