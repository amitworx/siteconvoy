<?php
/**
 * Migration Scope Planner.
 *
 * Deterministic, read-only planner that computes database table scopes,
 * exclusions, and metadata-only dependency summaries for all four SiteConvoy
 * migration modes.
 *
 * Security boundary: Enforces required capabilities (manage_network on multisite;
 * manage_options on standalone) before disclosing any source inventory or plan.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Planning;

use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Contracts\MigrationPlan;
use SiteConvoy\Contracts\PreflightFinding;
use SiteConvoy\Contracts\PreflightReport;
use SiteConvoy\Discovery\SourceDiscoveryService;
use SiteConvoy\Preflight\PreflightService;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MigrationScopePlanner
 */
class MigrationScopePlanner {

	/**
	 * Preflight service instance.
	 *
	 * @var PreflightService
	 */
	private $preflight_service;

	/**
	 * Source discovery service instance.
	 *
	 * @var SourceDiscoveryService
	 */
	private $discovery_service;

	/**
	 * Constructor.
	 *
	 * @param PreflightService|null       $preflight_service Optional preflight service.
	 * @param SourceDiscoveryService|null $discovery_service Optional discovery service.
	 */
	public function __construct( PreflightService $preflight_service = null, SourceDiscoveryService $discovery_service = null ) {
		$this->discovery_service = $discovery_service ? $discovery_service : new SourceDiscoveryService();
		$this->preflight_service = $preflight_service ? $preflight_service : new PreflightService( $this->discovery_service );
	}

	/**
	 * Generate a deterministic migration scope plan for the requested mode and source site.
	 *
	 * @param string   $mode           Canonical migration mode.
	 * @param int|null $source_site_id Optional source site ID.
	 * @return MigrationPlan Deterministic preview plan.
	 */
	public function plan( $mode, $source_site_id = null ) {
		global $wpdb;

		// 1. Run preflight to enforce capabilities, topology rules, site ID validation, and source inspection.
		$report = $this->preflight_service->run( $mode, $source_site_id );

		// 2. If preflight was unauthorized or blocked, return an immediate fail-closed plan.
		if ( PreflightReport::STATUS_UNAUTHORIZED === $report->get_status() ) {
			return new MigrationPlan(
				$mode,
				$source_site_id,
				'unauthorized',
				array(),
				array(),
				$report,
				array()
			);
		}

		if ( PreflightReport::STATUS_BLOCKED === $report->get_status() ) {
			return new MigrationPlan(
				$mode,
				$source_site_id,
				'blocked',
				array(
					'blog'       => array(),
					'global'     => array(),
					'ms_global'  => array(),
					'unresolved' => array(),
				),
				array(
					'themes'     => array(),
					'plugins'    => array(),
					'mu_plugins' => array(),
					'dropins'    => array(),
					'media'      => array(),
				),
				$report,
				array()
			);
		}

		$inventory = $report->get_source_inventory();
		if ( ! is_array( $inventory ) ) {
			return new MigrationPlan(
				$mode,
				$source_site_id,
				'blocked',
				array(
					'blog'       => array(),
					'global'     => array(),
					'ms_global'  => array(),
					'unresolved' => array(),
				),
				array(
					'themes'     => array(),
					'plugins'    => array(),
					'mu_plugins' => array(),
					'dropins'    => array(),
					'media'      => array(),
				),
				$report,
				array()
			);
		}

		// 3. Classify database tables according to migration mode rules.
		$tables_result = $this->classify_tables( $mode, $source_site_id, $inventory, $wpdb );
		$tables        = $tables_result['tables'];
		$has_missing   = ! empty( $tables_result['has_missing'] );

		// 4. Extract metadata-only dependency references.
		$dependencies = $this->extract_dependencies( $inventory, $mode );

		// 5. Structure source topology metadata.
		$source_topology = array(
			'is_multisite'       => ! empty( $inventory['environment']['is_multisite'] ),
			'current_network_id' => isset( $inventory['environment']['current_network_id'] ) ? (int) $inventory['environment']['current_network_id'] : 1,
			'base_prefix'        => isset( $inventory['environment']['base_prefix'] ) ? (string) $inventory['environment']['base_prefix'] : 'wp_',
			'site_count'         => isset( $inventory['network_sites']['total_count'] ) ? (int) $inventory['network_sites']['total_count'] : 1,
			'network_sites'      => isset( $inventory['network_sites']['sites'] ) ? $inventory['network_sites']['sites'] : array(),
		);

		// If any required core table is missing, the plan must fail closed with status 'blocked'.
		if ( $has_missing ) {
			$findings            = $report->get_findings();
			$missing_table_names = array();
			foreach ( $tables['blog'] as $tbl ) {
				if ( 'missing' === $tbl['status'] ) {
					$missing_table_names[] = $tbl['table_name'];
				}
			}
			foreach ( $tables['global'] as $tbl ) {
				if ( 'missing' === $tbl['status'] ) {
					$missing_table_names[] = $tbl['table_name'];
				}
			}
			foreach ( $tables['ms_global'] as $tbl ) {
				if ( 'missing' === $tbl['status'] ) {
					$missing_table_names[] = $tbl['table_name'];
				}
			}

			$findings[] = new PreflightFinding(
				'SC_MISSING_CORE_TABLES_PLAN',
				PreflightFinding::SEVERITY_BLOCKER,
				sprintf(
					'Required database tables are physically missing from the source database: %s.',
					implode( ', ', array_slice( $missing_table_names, 0, 10 ) )
				),
				array(
					'missing_tables' => $missing_table_names,
					'total_missing'  => count( $missing_table_names ),
				)
			);

			$blocked_report = new PreflightReport(
				$report->get_mode(),
				$report->get_source_site_id(),
				PreflightReport::STATUS_BLOCKED,
				false,
				$report->get_target_expectations(),
				$inventory,
				$findings
			);

			return new MigrationPlan(
				$mode,
				$source_site_id,
				'blocked',
				$tables,
				$dependencies,
				$blocked_report,
				$source_topology
			);
		}

		return new MigrationPlan(
			$mode,
			$source_site_id,
			'needs_review',
			$tables,
			$dependencies,
			$report,
			$source_topology
		);
	}

	/**
	 * Classify database tables for candidate scope based on the migration mode.
	 *
	 * @param string               $mode           Migration mode.
	 * @param int|null             $source_site_id Source site ID.
	 * @param array<string, mixed> $inventory      Source discovery inventory.
	 * @param \wpdb                $wpdb           Database instance.
	 * @return array{tables: array<string, array<int, array<string, mixed>>>, has_missing: bool}
	 */
	private function classify_tables( $mode, $source_site_id, array $inventory, $wpdb ) {
		$classified = array(
			'blog'       => array(),
			'global'     => array(),
			'ms_global'  => array(),
			'unresolved' => array(),
		);

		$has_missing = false;

		// Build a fast lookup of physically verified database tables.
		$physical_lookup = array();
		if ( isset( $inventory['tables']['physical_table_names'] ) && is_array( $inventory['tables']['physical_table_names'] ) ) {
			$physical_lookup = array_fill_keys( $inventory['tables']['physical_table_names'], true );
		} else {
			// Fallback check against SHOW TABLES if physical_table_names key is absent.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$col = $wpdb->get_col( 'SHOW TABLES' );
			if ( is_array( $col ) ) {
				$physical_lookup = array_fill_keys( $col, true );
			}
		}

		// Case 1 & Case 2: Subsite to Standalone OR Subsite to Multisite.
		if ( MigrationMode::SUBSITE_TO_STANDALONE === $mode || MigrationMode::SUBSITE_TO_MULTISITE === $mode ) {
			// Blog core tables are included candidates.
			if ( isset( $inventory['tables']['core_blog'] ) && is_array( $inventory['tables']['core_blog'] ) ) {
				foreach ( $inventory['tables']['core_blog'] as $logical => $meta ) {
					$table_name = isset( $meta['table'] ) ? $meta['table'] : '';
					$exists     = ! empty( $meta['exists'] ) || isset( $physical_lookup[ $table_name ] );
					$status     = $exists ? 'included' : 'missing';
					if ( ! $exists ) {
						$has_missing = true;
					}
					$reason = $exists
						? sprintf( 'Core blog table for site ID %d.', (int) $source_site_id )
						: sprintf( 'Required core blog table %s is missing from source database.', $table_name );

					$classified['blog'][] = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'blog',
						'status'       => $status,
						'reason'       => $reason,
					);
				}
			}

			// Global identity tables (users, usermeta) are EXCLUDED from automated transfer and require selective review.
			if ( isset( $inventory['tables']['core_global'] ) && is_array( $inventory['tables']['core_global'] ) ) {
				foreach ( $inventory['tables']['core_global'] as $logical => $meta ) {
					$classified['global'][] = array(
						'logical_name' => $logical,
						'table_name'   => isset( $meta['table'] ) ? $meta['table'] : '',
						'scope'        => 'global',
						'status'       => 'excluded',
						'reason'       => 'Shared network user tables are excluded from automated subsite transfer; individual user accounts and roles require explicit selective mapping review.',
					);
				}
			}

			// Multisite global tables (blogs, site, sitemeta, etc.) are EXCLUDED.
			if ( isset( $inventory['tables']['core_ms_global'] ) && is_array( $inventory['tables']['core_ms_global'] ) ) {
				foreach ( $inventory['tables']['core_ms_global'] as $logical => $meta ) {
					$classified['ms_global'][] = array(
						'logical_name' => $logical,
						'table_name'   => isset( $meta['table'] ) ? $meta['table'] : '',
						'scope'        => 'ms_global',
						'status'       => 'excluded',
						'reason'       => 'Network governance table belongs to the source multisite network and is excluded from subsite migration.',
					);
				}
			}
		} elseif ( MigrationMode::FULL_NETWORK_TO_NEW_ENV === $mode ) {
			// Case 3: Whole Multisite Network to New Environment.
			// All discovered network sites' core blog tables are candidates.
			$network_sites = isset( $inventory['network_sites']['sites'] ) && is_array( $inventory['network_sites']['sites'] )
				? $inventory['network_sites']['sites']
				: array();

			$seen_blog_tbls = array();

			foreach ( $network_sites as $net_site ) {
				$site_id     = isset( $net_site['blog_id'] ) ? (int) $net_site['blog_id'] : 1;
				$site_tables = $wpdb->tables( 'blog', true, $site_id );

				foreach ( $site_tables as $logical => $table_name ) {
					if ( isset( $seen_blog_tbls[ $table_name ] ) ) {
						continue;
					}
					$seen_blog_tbls[ $table_name ] = true;

					$exists = isset( $physical_lookup[ $table_name ] );
					$status = $exists ? 'included' : 'missing';
					if ( ! $exists ) {
						$has_missing = true;
					}
					$reason = $exists
						? sprintf( 'Core blog table for network site ID %d.', $site_id )
						: sprintf( 'Required core blog table %s for network site ID %d is physically missing from the source database.', $table_name, $site_id );

					$classified['blog'][] = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'blog',
						'status'       => $status,
						'reason'       => $reason,
					);
				}
			}

			// Global identity tables (users, usermeta) are candidates, but marked needs_review.
			if ( isset( $inventory['tables']['core_global'] ) && is_array( $inventory['tables']['core_global'] ) ) {
				foreach ( $inventory['tables']['core_global'] as $logical => $meta ) {
					$table_name = isset( $meta['table'] ) ? $meta['table'] : '';
					$exists     = ! empty( $meta['exists'] ) || isset( $physical_lookup[ $table_name ] );
					$status     = $exists ? 'needs_review' : 'missing';
					if ( ! $exists ) {
						$has_missing = true;
					}

					$classified['global'][] = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'global',
						'status'       => $status,
						'reason'       => 'Shared network user table candidate for full network migration; requires operator verification and collision review.',
					);
				}
			}

			// Multisite global tables (blogs, blogmeta, site, sitemeta, signups, registration_log) are included candidates if they exist physically.
			if ( isset( $inventory['tables']['core_ms_global'] ) && is_array( $inventory['tables']['core_ms_global'] ) ) {
				foreach ( $inventory['tables']['core_ms_global'] as $logical => $meta ) {
					$table_name = isset( $meta['table'] ) ? $meta['table'] : '';
					$exists     = ! empty( $meta['exists'] ) || isset( $physical_lookup[ $table_name ] );
					$status     = $exists ? 'included' : 'missing';
					if ( ! $exists ) {
						$has_missing = true;
					}

					$classified['ms_global'][] = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'ms_global',
						'status'       => $status,
						'reason'       => $exists
							? 'Core multisite network governance table included in full-network candidate scope.'
							: sprintf( 'Required core multisite network governance table %s is physically missing from database.', $table_name ),
					);
				}
			}
		} elseif ( MigrationMode::STANDALONE_TO_SUBSITE === $mode ) {
			// Case 4: Standalone to Multisite Subsite.
			if ( isset( $inventory['tables']['core_blog'] ) && is_array( $inventory['tables']['core_blog'] ) ) {
				foreach ( $inventory['tables']['core_blog'] as $logical => $meta ) {
					$table_name = isset( $meta['table'] ) ? $meta['table'] : '';
					$exists     = ! empty( $meta['exists'] ) || isset( $physical_lookup[ $table_name ] );
					$status     = $exists ? 'included' : 'missing';
					if ( ! $exists ) {
						$has_missing = true;
					}

					$classified['blog'][] = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'blog',
						'status'       => $status,
						'reason'       => 'Core standalone blog table candidate for subsite import.',
					);
				}
			}

			if ( isset( $inventory['tables']['core_global'] ) && is_array( $inventory['tables']['core_global'] ) ) {
				foreach ( $inventory['tables']['core_global'] as $logical => $meta ) {
					$table_name = isset( $meta['table'] ) ? $meta['table'] : '';
					$exists     = ! empty( $meta['exists'] ) || isset( $physical_lookup[ $table_name ] );
					$status     = $exists ? 'needs_review' : 'missing';
					if ( ! $exists ) {
						$has_missing = true;
					}

					$classified['global'][] = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'global',
						'status'       => $status,
						'reason'       => 'Standalone user accounts require explicit target network identity collision review and role mapping approval.',
					);
				}
			}
		}

		// Surface physically existing deprecated/legacy core_old tables (e.g. sitecategories, categories) in unresolved as needs_review.
		$seen_old_tables = array();
		if ( isset( $inventory['tables']['core_old'] ) && is_array( $inventory['tables']['core_old'] ) ) {
			foreach ( $inventory['tables']['core_old'] as $logical => $meta ) {
				$table_name = isset( $meta['table'] ) ? $meta['table'] : '';
				if ( ! empty( $meta['exists'] ) && '' !== $table_name && ! isset( $seen_old_tables[ $table_name ] ) ) {
					$seen_old_tables[ $table_name ] = true;

					$entry = array(
						'logical_name' => $logical,
						'table_name'   => $table_name,
						'scope'        => 'core_old',
						'status'       => 'needs_review',
						'reason'       => 'Deprecated or legacy WordPress core table detected physically in database; requires operator review for legacy data retention.',
					);

					if ( null !== $source_site_id ) {
						$entry['site_id'] = (int) $source_site_id;
					} elseif ( MigrationMode::FULL_NETWORK_TO_NEW_ENV === $mode && 'sitecategories' !== $logical ) {
						$entry['site_id'] = 1;
					}

					$classified['unresolved'][] = $entry;
				}
			}
		}

		// In full-network mode, also inspect and surface physically existing old tables on all other network subsites.
		if ( MigrationMode::FULL_NETWORK_TO_NEW_ENV === $mode ) {
			$network_sites = isset( $inventory['network_sites']['sites'] ) && is_array( $inventory['network_sites']['sites'] )
				? $inventory['network_sites']['sites']
				: array();

			foreach ( $network_sites as $net_site ) {
				$site_id = isset( $net_site['blog_id'] ) ? (int) $net_site['blog_id'] : 0;
				if ( $site_id <= 0 ) {
					continue;
				}

				$subsite_old = $wpdb->tables( 'old', true, $site_id );
				foreach ( $subsite_old as $logical => $table_name ) {
					// sitecategories is network-global on multisite; skip subsite duplication.
					if ( 'sitecategories' === $logical ) {
						continue;
					}

					if ( isset( $physical_lookup[ $table_name ] ) && ! isset( $seen_old_tables[ $table_name ] ) ) {
						$seen_old_tables[ $table_name ] = true;

						$classified['unresolved'][] = array(
							'logical_name' => $logical,
							'table_name'   => $table_name,
							'scope'        => 'core_old',
							'status'       => 'needs_review',
							'site_id'      => $site_id,
							'reason'       => sprintf( 'Deprecated or legacy WordPress core table %s detected physically for network site ID %d; requires operator review for legacy data retention.', $table_name, $site_id ),
						);
					}
				}
			}
		}

		// Unresolved custom and legacy tables (for all modes).
		$unknown_custom = isset( $inventory['tables']['unknown_custom'] ) && is_array( $inventory['tables']['unknown_custom'] )
			? $inventory['tables']['unknown_custom']
			: array();

		$all_unknown = array_merge(
			isset( $unknown_custom['blog_prefix_matched'] ) ? $unknown_custom['blog_prefix_matched'] : array(),
			isset( $unknown_custom['base_prefix_matched'] ) ? $unknown_custom['base_prefix_matched'] : array(),
			isset( $unknown_custom['unprefixed_other'] ) ? $unknown_custom['unprefixed_other'] : array()
		);

		foreach ( array_unique( $all_unknown ) as $tbl ) {
			$classified['unresolved'][] = array(
				'logical_name' => 'unknown_custom',
				'table_name'   => $tbl,
				'scope'        => 'custom',
				'status'       => 'unresolved',
				'reason'       => 'Non-core database table requires explicit operator review and disposition policy; table prefix does not prove ownership.',
			);
		}

		$legacy_ms = isset( $inventory['tables']['legacy_multisite'] ) && is_array( $inventory['tables']['legacy_multisite'] )
			? $inventory['tables']['legacy_multisite']
			: array();

		foreach ( $legacy_ms as $tbl ) {
			$classified['unresolved'][] = array(
				'logical_name' => 'legacy_multisite',
				'table_name'   => $tbl,
				'scope'        => 'legacy_multisite',
				'status'       => 'unresolved',
				'reason'       => 'Legacy pre-3.0 WordPress MU table (such as blog_versions); requires operator review.',
			);
		}

		return array(
			'tables'      => $classified,
			'has_missing' => $has_missing,
		);
	}

	/**
	 * Extract metadata-only dependency references without file reading or credentials.
	 *
	 * @param array<string, mixed> $inventory Source discovery inventory.
	 * @param string               $mode      Canonical migration mode.
	 * @return array<string, mixed>
	 */
	private function extract_dependencies( array $inventory, $mode ) {
		$deps = isset( $inventory['dependencies'] ) && is_array( $inventory['dependencies'] )
			? $inventory['dependencies']
			: array();

		$themes = array();
		if ( ! empty( $deps['stylesheet'] ) ) {
			$themes[] = array(
				'slug'   => (string) $deps['stylesheet'],
				'status' => 'review_required',
				'parent' => ! empty( $deps['is_child_theme'] ) ? (string) $deps['template'] : null,
			);
		}

		$plugins = array();
		if ( ! empty( $deps['active_plugins'] ) && is_array( $deps['active_plugins'] ) ) {
			foreach ( $deps['active_plugins'] as $plugin_slug ) {
				$plugins[] = array(
					'slug'     => (string) $plugin_slug,
					'sitewide' => false,
					'status'   => 'review_required',
				);
			}
		}

		if ( ! empty( $deps['network_activated_plugins'] ) && is_array( $deps['network_activated_plugins'] ) ) {
			foreach ( $deps['network_activated_plugins'] as $plugin_slug ) {
				$plugins[] = array(
					'slug'     => (string) $plugin_slug,
					'sitewide' => true,
					'status'   => 'review_required',
				);
			}
		}

		// MU-plugins: shallow metadata filenames only.
		$mu_plugins = array();
		if ( ! empty( $deps['mu_plugins'] ) && is_array( $deps['mu_plugins'] ) ) {
			foreach ( $deps['mu_plugins'] as $mu_file ) {
				$mu_plugins[] = array(
					'filename' => (string) $mu_file,
					'status'   => 'review_required',
				);
			}
		}

		$dropins = array();
		if ( ! empty( $deps['dropins'] ) && is_array( $deps['dropins'] ) ) {
			foreach ( $deps['dropins'] as $dropin_filename ) {
				$dropins[] = array(
					'filename' => (string) $dropin_filename,
					'status'   => 'review_required',
				);
			}
		}

		$fs    = isset( $inventory['filesystem'] ) && is_array( $inventory['filesystem'] ) ? $inventory['filesystem'] : array();
		$media = array(
			'layout'               => ! empty( $fs['uses_legacy_blogs_dir'] ) ? 'legacy_blogs_dir' : 'standard',
			'ms_files_rewriting'   => ! empty( $fs['ms_files_rewriting'] ),
			'custom_uploads_const' => ! empty( $fs['custom_uploads_constant'] ),
			'offload_indicators'   => isset( $fs['offload_indicators'] ) ? $fs['offload_indicators'] : array(),
		);

		// Per-site dependencies for full network mode.
		$per_site = array();
		if ( MigrationMode::FULL_NETWORK_TO_NEW_ENV === $mode ) {
			$network_sites = isset( $inventory['network_sites']['sites'] ) && is_array( $inventory['network_sites']['sites'] )
				? $inventory['network_sites']['sites']
				: array();

			foreach ( $network_sites as $net_site ) {
				$site_id = isset( $net_site['blog_id'] ) ? (int) $net_site['blog_id'] : 1;
				if ( $site_id <= 0 ) {
					continue;
				}

				$site_deps = $this->collect_site_dependencies( $site_id );
				if ( ! empty( $site_deps ) ) {
					$per_site[ $site_id ] = $site_deps;
				}
			}
		}

		$result = array(
			'themes'     => $themes,
			'plugins'    => $plugins,
			'mu_plugins' => $mu_plugins,
			'dropins'    => $dropins,
			'media'      => $media,
		);

		if ( ! empty( $per_site ) ) {
			$result['per_site'] = $per_site;
		}

		return $result;
	}

	/**
	 * Collect safe, bounded metadata-only theme and plugin dependencies for a single network site.
	 * Strictly wraps switch_to_blog in try/finally to restore blog context even on exceptions.
	 *
	 * @param int $site_id Target site ID.
	 * @return array<string, mixed>
	 */
	private function collect_site_dependencies( $site_id ) {
		$switched = false;

		try {
			if ( function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $site_id );
				$switched = true;
			}

			$stylesheet = function_exists( 'get_option' ) ? (string) get_option( 'stylesheet', '' ) : '';
			$template   = function_exists( 'get_option' ) ? (string) get_option( 'template', '' ) : '';
			$is_child   = ( '' !== $stylesheet && '' !== $template && $stylesheet !== $template );

			$active_plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
			if ( ! is_array( $active_plugins ) ) {
				$active_plugins = array();
			}
			$active_plugins = array_values( array_map( 'strval', $active_plugins ) );

			return array(
				'theme'   => array(
					'stylesheet' => $stylesheet,
					'template'   => $template,
					'is_child'   => $is_child,
				),
				'plugins' => $active_plugins,
			);
		} finally {
			if ( $switched && function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
		}
	}
}
