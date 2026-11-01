<?php
/**
 * Read-Only Source Discovery Service.
 *
 * Internal privileged service that inspects the current WordPress installation
 * (standalone or multisite) using WordPress core APIs ($wpdb, get_sites,
 * get_site, get_main_site_id, wp_upload_dir) without performing any database
 * mutations or exposing secrets, credentials, salts, or personal user data.
 *
 * Security boundary: Both PreflightService and SourceDiscoveryService enforce
 * WordPress capability checks (`manage_network` on multisite; `manage_options`
 * on standalone) before any inventory or site enumeration is performed.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Discovery;

use SiteConvoy\Contracts\MigrationMode;

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SourceDiscoveryService
 *
 * Privileged internal discovery service; enforces capability checks at its
 * public entry points (`discover()` and `enumerate_network_sites()`).
 */
class SourceDiscoveryService {

	/**
	 * Optional environment overrides for deterministic unit/integration testing.
	 *
	 * @var array<string, mixed>
	 */
	private $overrides;

	/**
	 * Optional custom callable for paginated site fetching: function( int $offset, int $number, int $network_id ): array.
	 *
	 * @var callable|null
	 */
	private $site_fetcher;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $overrides    Optional environment overrides for testing.
	 * @param callable|null             $site_fetcher Optional custom site page fetcher for testing pagination.
	 */
	public function __construct( $overrides = null, $site_fetcher = null ) {
		$this->overrides    = is_array( $overrides ) ? $overrides : array();
		$this->site_fetcher = is_callable( $site_fetcher ) ? $site_fetcher : null;
	}

	/**
	 * Collect a read-only source inventory for the current WordPress installation.
	 *
	 * Enforces privileged capability checks (`manage_network` on multisite,
	 * `manage_options` on standalone) and strict `$target_site_id` validation
	 * before inspecting the environment. Always preserves and restores the
	 * caller's active blog context if a blog switch is performed.
	 *
	 * @param int|string|null $target_site_id Optional specific site (blog) ID to inspect on multisite.
	 * @return array<string, mixed> Structured read-only source inventory.
	 * @throws \InvalidArgumentException When $target_site_id is malformed (non-integer string, float, bool, <= 0).
	 */
	public function discover( $target_site_id = null ) {
		global $wpdb;

		$this->assert_authorized();

		$site_id_validation = MigrationMode::validate_source_site_id( $target_site_id );
		if ( ! $site_id_validation['valid'] ) {
			throw new \InvalidArgumentException( 'Invalid source site ID supplied to SourceDiscoveryService::discover().' );
		}
		$validated_site_id = $site_id_validation['site_id'];

		$is_multisite    = function_exists( 'is_multisite' ) && is_multisite();
		$initial_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$main_site_id    = ( $is_multisite && function_exists( 'get_main_site_id' ) ) ? (int) get_main_site_id() : 1;
		$current_net_id  = $this->resolve_current_network_id( $is_multisite );
		$is_subdomain    = ( $is_multisite && function_exists( 'is_subdomain_install' ) ) ? (bool) is_subdomain_install() : false;
		$inspect_site_id = null !== $validated_site_id ? $validated_site_id : ( $is_multisite ? $main_site_id : 1 );

		$custom_user_table      = $this->resolve_custom_user_table();
		$custom_user_meta_table = $this->resolve_custom_user_meta_table();
		$network_count          = $this->resolve_network_count( $is_multisite );

		$network_sites    = $is_multisite ? $this->enumerate_network_sites( 100, $current_net_id ) : array();
		$target_site_info = $this->inspect_target_site( $inspect_site_id, $is_multisite, $main_site_id, $current_net_id, $wpdb );
		$tables_inventory = $this->inspect_tables( $inspect_site_id, $is_multisite, $network_sites, $wpdb );
		$files_and_deps   = $this->inspect_filesystem_and_dependencies(
			$inspect_site_id,
			$target_site_info['is_valid_source'],
			$is_multisite,
			$initial_blog_id
		);

		return array(
			'environment'        => array(
				'wp_version'             => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : 'unknown',
				'php_version'            => PHP_VERSION,
				'is_multisite'           => $is_multisite,
				'subdomain_install'      => $is_subdomain,
				'current_network_id'     => $current_net_id,
				'network_count'          => $network_count,
				'current_blog_id'        => $initial_blog_id,
				'main_site_id'           => $main_site_id,
				'base_prefix'            => isset( $wpdb->base_prefix ) ? (string) $wpdb->base_prefix : 'wp_',
				'custom_user_table'      => $custom_user_table,
				'custom_user_meta_table' => $custom_user_meta_table,
			),
			'target_source_site' => $target_site_info,
			'network_sites'      => array(
				'network_id'  => $current_net_id,
				'total_count' => count( $network_sites ),
				'sites'       => $network_sites,
			),
			'tables'             => $tables_inventory,
			'filesystem'         => $files_and_deps['filesystem'],
			'dependencies'       => $files_and_deps['dependencies'],
		);
	}

	/**
	 * Enumerate all sites in the specified (or current) multisite network using
	 * explicit `network_id` scoping and pagination so networks with >100 sites
	 * are never silently truncated and foreign-network sites are excluded.
	 *
	 * @param int      $batch_size Page size per query (default 100).
	 * @param int|null $network_id Optional explicit network ID (defaults to current network ID).
	 * @return array<int, array<string, mixed>> List of normalized site summaries belonging to the network.
	 */
	public function enumerate_network_sites( $batch_size = 100, $network_id = null ) {
		$this->assert_authorized();

		$is_multisite      = function_exists( 'is_multisite' ) && is_multisite();
		$target_network_id = null !== $network_id ? (int) $network_id : $this->resolve_current_network_id( $is_multisite );
		$batch_size        = max( 1, (int) $batch_size );
		$offset            = 0;
		$all_sites         = array();
		$main_id           = function_exists( 'get_main_site_id' ) ? (int) get_main_site_id( $target_network_id ) : 1;

		while ( true ) {
			if ( null !== $this->site_fetcher ) {
				$batch = call_user_func( $this->site_fetcher, $offset, $batch_size, $target_network_id );
			} elseif ( function_exists( 'get_sites' ) ) {
				$batch = get_sites(
					array(
						'network_id' => $target_network_id,
						'number'     => $batch_size,
						'offset'     => $offset,
						'orderby'    => 'id',
						'order'      => 'ASC',
					)
				);
			} else {
				$batch = array();
			}

			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $site ) {
				$normalized = $this->normalize_site_record( $site, $main_id, $target_network_id );
				// Enforce strict network_id match even if a custom fetcher returned mixed network records.
				if ( (int) $normalized['network_id'] !== $target_network_id ) {
					continue;
				}
				$all_sites[] = $normalized;
			}

			if ( count( $batch ) < $batch_size ) {
				break;
			}

			$offset += $batch_size;
		}

		return $all_sites;
	}

	/**
	 * Enforce privileged authorization at the discovery service boundary.
	 *
	 * @return void
	 * @throws \RuntimeException When current user lacks required capability.
	 */
	private function assert_authorized() {
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
		$required_cap = $is_multisite ? 'manage_network' : 'manage_options';
		$is_allowed   = function_exists( 'current_user_can' ) && current_user_can( $required_cap );

		if ( ! $is_allowed ) {
			$message = $is_multisite
				? 'Unauthorized direct invocation of SourceDiscoveryService; required capability "manage_network" is missing.'
				: 'Unauthorized direct invocation of SourceDiscoveryService; required capability "manage_options" is missing.';
			throw new \RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Resolve the current network ID from overrides or WordPress core.
	 *
	 * @param bool $is_multisite Whether running in multisite.
	 * @return int
	 */
	private function resolve_current_network_id( $is_multisite ) {
		if ( isset( $this->overrides['current_network_id'] ) ) {
			return (int) $this->overrides['current_network_id'];
		}
		if ( $is_multisite && function_exists( 'get_current_network_id' ) ) {
			return (int) get_current_network_id();
		}
		return 1;
	}

	/**
	 * Normalize a WP_Site object or associative array into a safe summary record.
	 *
	 * @param object|array<string, mixed> $site               WP_Site object or raw array.
	 * @param int                         $main_id            Main site ID for the current network.
	 * @param int                         $current_network_id Current network ID.
	 * @return array<string, mixed>
	 */
	private function normalize_site_record( $site, $main_id, $current_network_id = 1 ) {
		global $wpdb;

		if ( is_object( $site ) ) {
			$blog_id    = isset( $site->blog_id ) ? (int) $site->blog_id : 0;
			$network_id = isset( $site->site_id ) ? (int) $site->site_id : ( isset( $site->network_id ) ? (int) $site->network_id : 1 );
			$domain     = isset( $site->domain ) ? (string) $site->domain : '';
			$path       = isset( $site->path ) ? (string) $site->path : '/';
			$public     = isset( $site->public ) ? (int) $site->public : 1;
			$archived   = isset( $site->archived ) ? (int) $site->archived : 0;
			$mature     = isset( $site->mature ) ? (int) $site->mature : 0;
			$spam       = isset( $site->spam ) ? (int) $site->spam : 0;
			$deleted    = isset( $site->deleted ) ? (int) $site->deleted : 0;
		} else {
			$blog_id    = isset( $site['blog_id'] ) ? (int) $site['blog_id'] : 0;
			$network_id = isset( $site['network_id'] ) ? (int) $site['network_id'] : ( isset( $site['site_id'] ) ? (int) $site['site_id'] : 1 );
			$domain     = isset( $site['domain'] ) ? (string) $site['domain'] : '';
			$path       = isset( $site['path'] ) ? (string) $site['path'] : '/';
			$public     = isset( $site['public'] ) ? (int) $site['public'] : 1;
			$archived   = isset( $site['archived'] ) ? (int) $site['archived'] : 0;
			$mature     = isset( $site['mature'] ) ? (int) $site['mature'] : 0;
			$spam       = isset( $site['spam'] ) ? (int) $site['spam'] : 0;
			$deleted    = isset( $site['deleted'] ) ? (int) $site['deleted'] : 0;
		}

		$belongs_to_current = ( $network_id === (int) $current_network_id );

		$blog_prefix = ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) )
			? (string) $wpdb->get_blog_prefix( $blog_id )
			: 'wp_';

		return array(
			'blog_id'                    => $blog_id,
			'network_id'                 => $network_id,
			'belongs_to_current_network' => $belongs_to_current,
			'domain'                     => $domain,
			'path'                       => $path,
			'is_main_site'               => ( $belongs_to_current && $blog_id === (int) $main_id ),
			'blog_prefix'                => $blog_prefix,
			'status'                     => array(
				'public'   => $public,
				'archived' => $archived,
				'mature'   => $mature,
				'spam'     => $spam,
				'deleted'  => $deleted,
			),
		);
	}

	/**
	 * Inspect the specific target source site metadata and verify it belongs to the current network.
	 *
	 * @param int   $site_id        Site ID to inspect.
	 * @param bool  $is_multisite   Whether running in multisite.
	 * @param int   $main_site_id   Main site ID.
	 * @param int   $current_net_id Current network ID.
	 * @param \wpdb $wpdb           WordPress database instance.
	 * @return array<string, mixed>
	 */
	private function inspect_target_site( $site_id, $is_multisite, $main_site_id, $current_net_id, $wpdb ) {
		if ( ! $is_multisite ) {
			$home_url = function_exists( 'home_url' ) ? (string) home_url() : '';
			$parsed   = function_exists( 'wp_parse_url' ) ? wp_parse_url( $home_url ) : array();
			$domain   = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : 'localhost';
			$path     = is_array( $parsed ) && isset( $parsed['path'] ) && '' !== $parsed['path'] ? (string) $parsed['path'] : '/';

			return array(
				'blog_id'                    => 1,
				'exists'                     => true,
				'belongs_to_current_network' => true,
				'is_valid_source'            => true,
				'network_id'                 => 1,
				'domain'                     => $domain,
				'path'                       => $path,
				'is_main_site'               => true,
				'blog_prefix'                => (string) $wpdb->base_prefix,
				'status'                     => array(
					'public'   => 1,
					'archived' => 0,
					'mature'   => 0,
					'spam'     => 0,
					'deleted'  => 0,
				),
			);
		}

		if ( $site_id <= 0 || ! function_exists( 'get_site' ) ) {
			return array(
				'blog_id'                    => $site_id,
				'exists'                     => false,
				'belongs_to_current_network' => false,
				'is_valid_source'            => false,
				'network_id'                 => 0,
				'domain'                     => '',
				'path'                       => '',
				'is_main_site'               => false,
				'blog_prefix'                => '',
				'status'                     => array(),
			);
		}

		$site_obj = get_site( $site_id );
		if ( ! $site_obj ) {
			return array(
				'blog_id'                    => $site_id,
				'exists'                     => false,
				'belongs_to_current_network' => false,
				'is_valid_source'            => false,
				'network_id'                 => 0,
				'domain'                     => '',
				'path'                       => '',
				'is_main_site'               => false,
				'blog_prefix'                => (string) $wpdb->get_blog_prefix( $site_id ),
				'status'                     => array(),
			);
		}

		$normalized                    = $this->normalize_site_record( $site_obj, $main_site_id, $current_net_id );
		$normalized['exists']          = true;
		$normalized['is_valid_source'] = (bool) $normalized['belongs_to_current_network'];

		return $normalized;
	}

	/**
	 * Inspect and classify WordPress core, multisite global, legacy, and custom database tables.
	 *
	 * @param int                            $site_id       Site ID for blog-scoped tables.
	 * @param bool                           $is_multisite  Whether current install is multisite.
	 * @param array<int,array<string,mixed>> $network_sites Enumerated network sites.
	 * @param \wpdb                          $wpdb          WordPress database object.
	 * @return array<string, mixed>
	 */
	private function inspect_tables( $site_id, $is_multisite, array $network_sites, $wpdb ) {
		// Fetch physical tables in current database via SHOW TABLES.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$physical_tables = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $physical_tables ) ) {
			$physical_tables = array();
		}
		$physical_lookup = array_fill_keys( $physical_tables, true );

		$blog_id_for_tables = $site_id > 0 ? $site_id : 1;
		$blog_prefix        = (string) $wpdb->get_blog_prefix( $blog_id_for_tables );
		$base_prefix        = (string) $wpdb->base_prefix;

		// 1. Core blog-scoped tables for the target site.
		$core_blog_map = $wpdb->tables( 'blog', true, $blog_id_for_tables );
		$core_blog     = array();
		$known_tables  = array();

		foreach ( $core_blog_map as $logical_key => $table_name ) {
			$core_blog[ $logical_key ]   = array(
				'table'  => $table_name,
				'exists' => isset( $physical_lookup[ $table_name ] ),
			);
			$known_tables[ $table_name ] = true;
		}

		// Also mark core blog tables of all other network sites as known core tables so they aren't misclassified as custom tables.
		foreach ( $network_sites as $net_site ) {
			$other_id     = isset( $net_site['blog_id'] ) ? (int) $net_site['blog_id'] : 0;
			$other_tables = $wpdb->tables( 'blog', true, $other_id );
			foreach ( $other_tables as $other_table_name ) {
				$known_tables[ $other_table_name ] = true;
			}
			$other_old_tables = $wpdb->tables( 'old', true, $other_id );
			foreach ( $other_old_tables as $other_old_name ) {
				$known_tables[ $other_old_name ] = true;
			}
		}

		// 2. Core global user tables (users, usermeta) vs ms_global tables.
		$ms_global_map  = $wpdb->tables( 'ms_global', true, $blog_id_for_tables );
		$all_global_map = $wpdb->tables( 'global', true, $blog_id_for_tables );

		$core_global    = array();
		$core_ms_global = array();

		foreach ( $all_global_map as $logical_key => $table_name ) {
			if ( isset( $ms_global_map[ $logical_key ] ) ) {
				continue;
			}
			$core_global[ $logical_key ] = array(
				'table'  => $table_name,
				'exists' => isset( $physical_lookup[ $table_name ] ),
			);
			$known_tables[ $table_name ] = true;
		}

		foreach ( $ms_global_map as $logical_key => $table_name ) {
			$core_ms_global[ $logical_key ] = array(
				'table'  => $table_name,
				'exists' => isset( $physical_lookup[ $table_name ] ),
			);
			$known_tables[ $table_name ]    = true;
		}

		// 3. Deprecated / old core tables (categories, post2cat, link2cat, and sitecategories on multisite).
		$old_map  = $wpdb->tables( 'old', true, $blog_id_for_tables );
		$core_old = array();
		foreach ( $old_map as $logical_key => $table_name ) {
			$core_old[ $logical_key ]    = array(
				'table'  => $table_name,
				'exists' => isset( $physical_lookup[ $table_name ] ),
			);
			$known_tables[ $table_name ] = true;
		}

		// 4. Legacy pre-3.0 WordPress MU tables not in wpdb::$ms_global_tables (e.g., blog_versions).
		$legacy_multisite                      = array();
		$legacy_blog_versions                  = $base_prefix . 'blog_versions';
		$known_tables[ $legacy_blog_versions ] = true;
		if ( isset( $physical_lookup[ $legacy_blog_versions ] ) ) {
			$legacy_multisite[] = $legacy_blog_versions;
		}

		// 5. Classify remaining physical tables as unknown/custom tables.
		$blog_prefix_matched = array();
		$base_prefix_matched = array();
		$unprefixed_other    = array();

		// Build a list of other subsite prefixes to distinguish target subsite custom tables from other subsites' custom tables.
		$other_subsite_prefixes = array();
		foreach ( $network_sites as $net_site ) {
			$other_id = isset( $net_site['blog_id'] ) ? (int) $net_site['blog_id'] : 0;
			if ( $other_id > 0 && $other_id !== $blog_id_for_tables ) {
				$other_prefix = (string) $wpdb->get_blog_prefix( $other_id );
				if ( $other_prefix !== $base_prefix ) {
					$other_subsite_prefixes[] = $other_prefix;
				}
			}
		}

		foreach ( $physical_tables as $physical_table ) {
			if ( isset( $known_tables[ $physical_table ] ) ) {
				continue;
			}

			// If inspecting a non-base subsite (e.g., wp_2_), check that prefix first.
			if ( $blog_prefix !== $base_prefix && 0 === strpos( $physical_table, $blog_prefix ) ) {
				$blog_prefix_matched[] = $physical_table;
				continue;
			}

			// Skip custom tables clearly belonging to a different numeric subsite prefix when inspecting Site 1 or another site.
			$belongs_to_other_subsite = false;
			foreach ( $other_subsite_prefixes as $other_prefix ) {
				if ( 0 === strpos( $physical_table, $other_prefix ) ) {
					$belongs_to_other_subsite = true;
					break;
				}
			}
			if ( $belongs_to_other_subsite ) {
				$base_prefix_matched[] = $physical_table;
				continue;
			}

			if ( 0 === strpos( $physical_table, $base_prefix ) ) {
				if ( $blog_prefix === $base_prefix ) {
					$blog_prefix_matched[] = $physical_table;
				} else {
					$base_prefix_matched[] = $physical_table;
				}
			} else {
				$unprefixed_other[] = $physical_table;
			}
		}

		return array(
			'core_blog'        => $core_blog,
			'core_global'      => $core_global,
			'core_ms_global'   => $core_ms_global,
			'core_old'         => $core_old,
			'legacy_multisite' => $legacy_multisite,
			'unknown_custom'   => array(
				'blog_prefix_matched' => $blog_prefix_matched,
				'base_prefix_matched' => $base_prefix_matched,
				'unprefixed_other'    => $unprefixed_other,
			),
		);
	}

	/**
	 * Inspect filesystem structure indicators and active plugin/theme/drop-in dependencies
	 * while strictly restoring blog context via try/finally.
	 *
	 * @param int  $inspect_site_id Target site ID.
	 * @param bool $site_exists     Whether the target site exists.
	 * @param bool $is_multisite    Whether current environment is multisite.
	 * @param int  $initial_blog_id Caller's original blog ID before discovery.
	 * @return array<string, array<string, mixed>>
	 */
	private function inspect_filesystem_and_dependencies( $inspect_site_id, $site_exists, $is_multisite, $initial_blog_id ) {
		$switched = false;

		try {
			if (
				$is_multisite
				&& $site_exists
				&& $inspect_site_id > 0
				&& $inspect_site_id !== $initial_blog_id
				&& function_exists( 'switch_to_blog' )
			) {
				switch_to_blog( $inspect_site_id );
				$switched = true;
			}

			// Upload directory structure (relative to ABSPATH where possible to avoid leaking host user paths).
			$uploads_info     = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
			$basedir          = isset( $uploads_info['basedir'] ) ? (string) $uploads_info['basedir'] : '';
			$relative_basedir = $this->normalize_relative_path( $basedir );

			$ms_files_rewriting = $is_multisite && function_exists( ' get_site_option' )
				? (bool) get_site_option( 'ms_files_rewriting', false )
				: false;
			if ( $is_multisite && function_exists( 'get_site_option' ) ) {
				$ms_files_rewriting = (bool) get_site_option( 'ms_files_rewriting', false );
			}

			$uses_legacy_blogs_dir = ( false !== strpos( str_replace( '\\', '/', $relative_basedir ), 'blogs.dir' ) )
				|| $ms_files_rewriting;

			$custom_uploads_defined = defined( 'UPLOADS' ) && '' !== constant( 'UPLOADS' );

			// Active theme and parent theme slugs.
			$stylesheet = function_exists( 'get_option' ) ? (string) get_option( 'stylesheet', '' ) : '';
			$template   = function_exists( 'get_option' ) ? (string) get_option( 'template', '' ) : '';
			$is_child   = ( '' !== $stylesheet && '' !== $template && $stylesheet !== $template );

			// Site-active plugins.
			$active_plugins = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
			if ( ! is_array( $active_plugins ) ) {
				$active_plugins = array();
			}
			$active_plugins = array_values( array_map( 'strval', $active_plugins ) );

			// Network-activated plugins (multisite only).
			$network_plugins = array();
			if ( $is_multisite && function_exists( 'get_site_option' ) ) {
				$raw_sitewide = get_site_option( 'active_sitewide_plugins', array() );
				if ( is_array( $raw_sitewide ) ) {
					$network_plugins = array_values( array_map( 'strval', array_keys( $raw_sitewide ) ) );
				}
			}

			// MU-plugins and drop-ins (shallow directory scan of filenames only).
			$mu_plugins = $this->list_php_basenames( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '' );
			$dropins    = $this->detect_dropins( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' );

			// Offloaded media / custom stream indicators.
			$offload_indicators = $this->detect_offload_indicators( $active_plugins, $network_plugins );

			return array(
				'filesystem'   => array(
					'uploads_relative_basedir' => $relative_basedir,
					'uploads_error'            => isset( $uploads_info['error'] ) && false !== $uploads_info['error']
						? (string) $uploads_info['error']
						: null,
					'uses_legacy_blogs_dir'    => $uses_legacy_blogs_dir,
					'ms_files_rewriting'       => $ms_files_rewriting,
					'custom_uploads_constant'  => $custom_uploads_defined,
					'offload_indicators'       => $offload_indicators,
				),
				'dependencies' => array(
					'stylesheet'                => $stylesheet,
					'template'                  => $template,
					'is_child_theme'            => $is_child,
					'active_plugins'            => $active_plugins,
					'network_activated_plugins' => $network_plugins,
					'mu_plugins'                => $mu_plugins,
					'dropins'                   => $dropins,
				),
			);
		} finally {
			if ( $switched && function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Resolve CUSTOM_USER_TABLE from test overrides or defined runtime constants.
	 *
	 * @return string|null
	 */
	private function resolve_custom_user_table() {
		if ( array_key_exists( 'custom_user_table', $this->overrides ) ) {
			return null !== $this->overrides['custom_user_table'] ? (string) $this->overrides['custom_user_table'] : null;
		}
		if ( defined( 'CUSTOM_USER_TABLE' ) && is_string( CUSTOM_USER_TABLE ) && '' !== CUSTOM_USER_TABLE ) {
			return CUSTOM_USER_TABLE;
		}
		return null;
	}

	/**
	 * Resolve CUSTOM_USER_META_TABLE from test overrides or defined runtime constants.
	 *
	 * @return string|null
	 */
	private function resolve_custom_user_meta_table() {
		if ( array_key_exists( 'custom_user_meta_table', $this->overrides ) ) {
			return null !== $this->overrides['custom_user_meta_table'] ? (string) $this->overrides['custom_user_meta_table'] : null;
		}
		if ( defined( 'CUSTOM_USER_META_TABLE' ) && is_string( CUSTOM_USER_META_TABLE ) && '' !== CUSTOM_USER_META_TABLE ) {
			return CUSTOM_USER_META_TABLE;
		}
		return null;
	}

	/**
	 * Resolve the count of networks (`wp_site` rows) in a multisite installation.
	 *
	 * @param bool $is_multisite Whether running in multisite.
	 * @return int
	 */
	private function resolve_network_count( $is_multisite ) {
		if ( isset( $this->overrides['network_count'] ) ) {
			return (int) $this->overrides['network_count'];
		}
		if ( ! $is_multisite ) {
			return 1;
		}
		if ( function_exists( 'get_networks' ) ) {
			$networks = get_networks( array( 'count' => true ) );
			return max( 1, (int) $networks );
		}
		return 1;
	}

	/**
	 * Convert an absolute path under ABSPATH into a portable relative path.
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string Normalized path.
	 */
	private function normalize_relative_path( $path ) {
		if ( '' === $path ) {
			return '';
		}
		$normalized_path = str_replace( '\\', '/', $path );
		$normalized_abs  = str_replace( '\\', '/', ABSPATH );

		if ( 0 === strpos( $normalized_path, $normalized_abs ) ) {
			return ltrim( substr( $normalized_path, strlen( $normalized_abs ) ), '/' );
		}
		return basename( $normalized_path );
	}

	/**
	 * List top-level `.php` filenames inside a directory without deep traversal.
	 *
	 * @param string $dir Directory path.
	 * @return string[] Sorted list of basenames.
	 */
	private function list_php_basenames( $dir ) {
		if ( '' === $dir || ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return array();
		}

		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$files = array();
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( '.php' === substr( $entry, -4 ) && is_file( rtrim( $dir, '/\\' ) . '/' . $entry ) ) {
				$files[] = $entry;
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Detect known WordPress drop-in files present in WP_CONTENT_DIR.
	 *
	 * @param string $content_dir WP_CONTENT_DIR path.
	 * @return string[] List of present drop-in filenames.
	 */
	private function detect_dropins( $content_dir ) {
		if ( '' === $content_dir || ! is_dir( $content_dir ) ) {
			return array();
		}

		$known_dropins = array(
			'advanced-cache.php',
			'blog-deleted.php',
			'blog-inactive.php',
			'blog-suspended.php',
			'db-error.php',
			'db.php',
			'install.php',
			'maintenance.php',
			'object-cache.php',
			'php-error.php',
			'fatal-error-handler.php',
			'sunrise.php',
		);

		$found = array();
		foreach ( $known_dropins as $dropin ) {
			if ( is_file( rtrim( $content_dir, '/\\' ) . '/' . $dropin ) ) {
				$found[] = $dropin;
			}
		}
		return $found;
	}

	/**
	 * Detect common media offload plugins or custom stream indicators.
	 *
	 * @param string[] $active_plugins  Site-active plugins.
	 * @param string[] $network_plugins Network-activated plugins.
	 * @return string[] List of detected offload indicators.
	 */
	private function detect_offload_indicators( array $active_plugins, array $network_plugins ) {
		$all_plugins = array_unique( array_merge( $active_plugins, $network_plugins ) );
		$keywords    = array(
			'amazon-s3-and-cloudfront',
			'wp-Offload-media',
			's3-uploads',
			'media-cloud',
			'wp-stateless',
		);

		$indicators = array();
		foreach ( $all_plugins as $plugin_slug ) {
			$lower = strtolower( $plugin_slug );
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $lower, strtolower( $kw ) ) ) {
					$indicators[] = 'plugin:' . $plugin_slug;
				}
			}
		}

		return array_values( array_unique( $indicators ) );
	}
}
