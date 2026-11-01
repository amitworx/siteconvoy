<?php
/**
 * Read-Only Preflight Validation Service.
 *
 * Enforces capability checks before collecting detailed source inventory,
 * validates migration mode compatibility against the current WordPress runtime,
 * evaluates source database/site/filesystem risks, and produces a safe,
 * JSON-serializable PreflightReport.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Preflight;

use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Contracts\PreflightFinding;
use SiteConvoy\Contracts\PreflightReport;
use SiteConvoy\Discovery\SourceDiscoveryService;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PreflightService
 */
class PreflightService {

	/**
	 * Read-only source discovery service.
	 *
	 * @var SourceDiscoveryService
	 */
	private $discovery_service;

	/**
	 * Constructor.
	 *
	 * @param SourceDiscoveryService|null $discovery_service Optional injected discovery service.
	 */
	public function __construct( SourceDiscoveryService $discovery_service = null ) {
		$this->discovery_service = null !== $discovery_service
			? $discovery_service
			: new SourceDiscoveryService();
	}

	/**
	 * Execute a read-only source preflight check for the requested migration mode.
	 *
	 * @param string               $mode                Migration mode identifier (see MigrationMode).
	 * @param int|null             $source_site_id      Optional source site (blog) ID for subsite modes.
	 * @param array<string, mixed> $target_expectations Optional caller-provided target expectations.
	 * @return PreflightReport Structured read-only preflight report.
	 */
	public function run( $mode, $source_site_id = null, array $target_expectations = array() ) {
		$normalized_mode    = is_string( $mode ) ? trim( $mode ) : '';
		$normalized_site_id = null !== $source_site_id ? (int) $source_site_id : null;
		$is_multisite       = function_exists( 'is_multisite' ) && is_multisite();

		$resolved_target = array(
			'expected_type'       => isset( $target_expectations['expected_type'] )
				? (string) $target_expectations['expected_type']
				: MigrationMode::expected_target_type( $normalized_mode ),
			'verification_status' => 'unverified',
		);

		// 1. Authorization guard BEFORE any detailed source discovery inventory is collected.
		$required_cap = $is_multisite ? 'manage_network' : 'manage_options';
		$is_allowed   = function_exists( 'current_user_can' ) && current_user_can( $required_cap );

		if ( ! $is_allowed ) {
			$forbidden_finding = new PreflightFinding(
				'SC_AUTH_FORBIDDEN',
				PreflightFinding::SEVERITY_BLOCKER,
				sprintf(
					'Current user lacks the required "%s" capability to run SiteConvoy source discovery and preflight.',
					$required_cap
				),
				array(
					'required_capability' => $required_cap,
					'is_multisite'        => $is_multisite,
				)
			);

			return new PreflightReport(
				$normalized_mode,
				$normalized_site_id,
				PreflightReport::STATUS_UNAUTHORIZED,
				false,
				$resolved_target,
				null,
				array( $forbidden_finding )
			);
		}

		$findings = array();

		// 2. Validate migration mode identifier.
		if ( ! MigrationMode::is_valid( $normalized_mode ) ) {
			$findings[] = new PreflightFinding(
				'SC_INVALID_MIGRATION_MODE',
				PreflightFinding::SEVERITY_BLOCKER,
				'The requested migration mode identifier is not recognized.',
				array(
					'requested_mode'  => $normalized_mode,
					'supported_modes' => MigrationMode::all(),
				)
			);

			return new PreflightReport(
				$normalized_mode,
				$normalized_site_id,
				PreflightReport::STATUS_BLOCKED,
				false,
				$resolved_target,
				null,
				$findings
			);
		}

		// 3. Validate mode compatibility with current source topology (multisite vs standalone).
		$requires_ms_source = MigrationMode::requires_multisite_source( $normalized_mode );
		if ( $requires_ms_source && ! $is_multisite ) {
			$findings[] = new PreflightFinding(
				'SC_MODE_SOURCE_MISMATCH',
				PreflightFinding::SEVERITY_BLOCKER,
				sprintf(
					'Migration mode "%s" requires a WordPress multisite network source, but the current installation is standalone.',
					$normalized_mode
				),
				array(
					'mode'         => $normalized_mode,
					'is_multisite' => false,
				)
			);
		} elseif ( ! $requires_ms_source && $is_multisite ) {
			$findings[] = new PreflightFinding(
				'SC_MODE_SOURCE_MISMATCH',
				PreflightFinding::SEVERITY_BLOCKER,
				sprintf(
					'Migration mode "%s" requires a standalone WordPress source, but the current installation is a multisite network.',
					$normalized_mode
				),
				array(
					'mode'         => $normalized_mode,
					'is_multisite' => true,
				)
			);
		}

		// 4. Validate required source site ID for subsite-scoped modes.
		if ( MigrationMode::requires_source_site_id( $normalized_mode ) ) {
			if ( null === $normalized_site_id || $normalized_site_id <= 0 ) {
				$findings[] = new PreflightFinding(
					'SC_SOURCE_SITE_ID_REQUIRED',
					PreflightFinding::SEVERITY_BLOCKER,
					sprintf(
						'Migration mode "%s" requires a positive integer source site (blog) ID.',
						$normalized_mode
					),
					array(
						'mode'           => $normalized_mode,
						'source_site_id' => $normalized_site_id,
					)
				);
			}
		}

		// 5. Collect read-only source inventory.
		$inventory = $this->discovery_service->discover( $normalized_site_id );

		// 6. Evaluate source site existence and status flags when a specific subsite is required.
		if ( $is_multisite && MigrationMode::requires_source_site_id( $normalized_mode ) && null !== $normalized_site_id && $normalized_site_id > 0 ) {
			$target_site = isset( $inventory['target_source_site'] ) ? $inventory['target_source_site'] : array();
			$exists      = ! empty( $target_site['exists'] );

			if ( ! $exists ) {
				$findings[] = new PreflightFinding(
					'SC_SOURCE_SITE_NOT_FOUND',
					PreflightFinding::SEVERITY_BLOCKER,
					sprintf(
						'Requested source site ID %d does not exist in the current multisite network.',
						$normalized_site_id
					),
					array(
						'source_site_id' => $normalized_site_id,
					)
				);
			} else {
				// Check non-standard site lifecycle flags (archived, spam, deleted).
				$status_flags = isset( $target_site['status'] ) && is_array( $target_site['status'] ) ? $target_site['status'] : array();
				$non_standard = array();
				foreach ( array( 'archived', 'spam', 'deleted', 'mature' ) as $flag ) {
					if ( ! empty( $status_flags[ $flag ] ) ) {
						$non_standard[] = $flag;
					}
				}

				if ( ! empty( $non_standard ) ) {
					$findings[] = new PreflightFinding(
						'SC_SOURCE_SITE_NON_STANDARD_STATUS',
						PreflightFinding::SEVERITY_WARNING,
						sprintf(
							'Source site ID %d has non-standard status flags (%s); verify whether this subsite should be migrated.',
							$normalized_site_id,
							implode( ', ', $non_standard )
						),
						array(
							'source_site_id' => $normalized_site_id,
							'flags'          => $non_standard,
						)
					);
				}

				// Check if source site is the main site (shares base_prefix with global/ms_global tables).
				if ( ! empty( $target_site['is_main_site'] ) ) {
					$findings[] = new PreflightFinding(
						'SC_MAIN_SITE_SHARED_PREFIX_ISOLATION',
						PreflightFinding::SEVERITY_INFO,
						'Selected source site is the network main site and shares $wpdb->base_prefix with global/network tables; export must strictly isolate blog-scoped core tables ($wpdb->tables("blog")) from ms_global tables.',
						array(
							'source_site_id' => $normalized_site_id,
							'blog_prefix'    => isset( $target_site['blog_prefix'] ) ? $target_site['blog_prefix'] : '',
						)
					);
				}
			}
		}

		// 7. Evaluate unsupported custom user tables (CUSTOM_USER_TABLE / CUSTOM_USER_META_TABLE).
		$env = isset( $inventory['environment'] ) ? $inventory['environment'] : array();
		if ( ! empty( $env['custom_user_table'] ) || ! empty( $env['custom_user_meta_table'] ) ) {
			$findings[] = new PreflightFinding(
				'SC_CUSTOM_USER_TABLE_UNSUPPORTED',
				PreflightFinding::SEVERITY_BLOCKER,
				'Custom user table constants (CUSTOM_USER_TABLE / CUSTOM_USER_META_TABLE) are defined and unsupported in SiteConvoy v1.',
				array(
					'custom_user_table'      => $env['custom_user_table'],
					'custom_user_meta_table' => $env['custom_user_meta_table'],
				)
			);
		}

		// 8. Evaluate unsupported multi-network installations (>1 wp_site rows) on multisite.
		if ( $is_multisite && isset( $env['network_count'] ) && (int) $env['network_count'] > 1 ) {
			$findings[] = new PreflightFinding(
				'SC_MULTI_NETWORK_UNSUPPORTED',
				PreflightFinding::SEVERITY_BLOCKER,
				'Multi-network WordPress installations (more than one network in wp_site) are unsupported in SiteConvoy v1.',
				array(
					'network_count' => (int) $env['network_count'],
				)
			);
		}

		// 9. Evaluate missing core blog tables when source site exists.
		$target_exists = ! empty( $inventory['target_source_site']['exists'] );
		if ( $target_exists && isset( $inventory['tables']['core_blog'] ) && is_array( $inventory['tables']['core_blog'] ) ) {
			$missing_core_blog = array();
			foreach ( $inventory['tables']['core_blog'] as $logical => $meta ) {
				if ( empty( $meta['exists'] ) ) {
					$missing_core_blog[] = isset( $meta['table'] ) ? $meta['table'] : $logical;
				}
			}
			if ( ! empty( $missing_core_blog ) ) {
				$findings[] = new PreflightFinding(
					'SC_MISSING_CORE_BLOG_TABLES',
					PreflightFinding::SEVERITY_BLOCKER,
					'One or more required WordPress core blog tables do not exist in the source database.',
					array(
						'missing_tables' => $missing_core_blog,
					)
				);
			}
		}

		// 10. Evaluate unknown/custom tables and legacy multisite tables.
		$unknown_blog_tables = isset( $inventory['tables']['unknown_custom']['blog_prefix_matched'] )
			? $inventory['tables']['unknown_custom']['blog_prefix_matched']
			: array();
		if ( ! empty( $unknown_blog_tables ) ) {
			$findings[] = new PreflightFinding(
				'SC_UNKNOWN_CUSTOM_TABLES_DETECTED',
				PreflightFinding::SEVERITY_WARNING,
				'Custom or plugin-created database tables matching the source site prefix were detected and require explicit policy handling.',
				array(
					'tables' => $unknown_blog_tables,
				)
			);
		}

		$legacy_ms_tables = isset( $inventory['tables']['legacy_multisite'] )
			? $inventory['tables']['legacy_multisite']
			: array();
		if ( ! empty( $legacy_ms_tables ) ) {
			$findings[] = new PreflightFinding(
				'SC_LEGACY_MULTISITE_TABLES_DETECTED',
				PreflightFinding::SEVERITY_WARNING,
				'Legacy pre-3.0 WordPress MU tables (such as blog_versions) exist in the source database outside the standard wpdb::$ms_global_tables catalog.',
				array(
					'tables' => $legacy_ms_tables,
				)
			);
		}

		// 11. Evaluate legacy blogs.dir / ms_files_rewriting or offloaded media indicators.
		$fs = isset( $inventory['filesystem'] ) ? $inventory['filesystem'] : array();
		if ( ! empty( $fs['uses_legacy_blogs_dir'] ) || ! empty( $fs['ms_files_rewriting'] ) ) {
			$findings[] = new PreflightFinding(
				'SC_LEGACY_BLOGS_DIR_UPLOADS',
				PreflightFinding::SEVERITY_WARNING,
				'Legacy multisite uploads layout (blogs.dir / ms_files_rewriting) detected; path normalization is required during media migration.',
				array(
					'uploads_relative_basedir' => isset( $fs['uploads_relative_basedir'] ) ? $fs['uploads_relative_basedir'] : '',
					'ms_files_rewriting'       => ! empty( $fs['ms_files_rewriting'] ),
				)
			);
		}

		if ( ! empty( $fs['offload_indicators'] ) ) {
			$findings[] = new PreflightFinding(
				'SC_OFFLOADED_MEDIA_INDICATORS',
				PreflightFinding::SEVERITY_WARNING,
				'Active plugin indicators suggest media attachments may be offloaded to external object storage.',
				array(
					'indicators' => $fs['offload_indicators'],
				)
			);
		}

		// 12. Always record that the destination environment is unverified during read-only source preflight.
		$findings[] = new PreflightFinding(
			'SC_TARGET_UNVERIFIED',
			PreflightFinding::SEVERITY_INFO,
			'Destination environment has not been inspected or verified; read-only source preflight cannot authorize migration execution.',
			$resolved_target
		);

		$has_blocker = false;
		foreach ( $findings as $finding ) {
			if ( $finding->is_blocker() ) {
				$has_blocker = true;
				break;
			}
		}

		$status = $has_blocker
			? PreflightReport::STATUS_BLOCKED
			: PreflightReport::STATUS_SOURCE_INSPECTED;

		return new PreflightReport(
			$normalized_mode,
			$normalized_site_id,
			$status,
			false,
			$resolved_target,
			$inventory,
			$findings
		);
	}
}
