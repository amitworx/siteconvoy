<?php
/**
 * WordPress Integration Tests for Read-Only Source Discovery and Preflight Service.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use WP_UnitTestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Contracts\PreflightFinding;
use SiteConvoy\Contracts\PreflightReport;
use SiteConvoy\Discovery\SourceDiscoveryService;
use SiteConvoy\Preflight\PreflightService;

/**
 * Class SourceDiscoveryAndPreflightTest
 */
class SourceDiscoveryAndPreflightTest extends WP_UnitTestCase {

	/**
	 * Verify WordPress 6.7 core wpdb::tables() catalog includes blogmeta in ms_global and sitecategories in old.
	 */
	public function test_wordpress_core_table_catalog_resolution() {
		global $wpdb;

		$ms_global_unprefixed = array_values( $wpdb->tables( 'ms_global', false ) );
		$this->assertContains( 'blogs', $ms_global_unprefixed );
		$this->assertContains( 'blogmeta', $ms_global_unprefixed, 'WordPress 6.7 ms_global catalog must include blogmeta.' );
		$this->assertContains( 'signups', $ms_global_unprefixed );
		$this->assertContains( 'site', $ms_global_unprefixed );
		$this->assertContains( 'sitemeta', $ms_global_unprefixed );
		$this->assertContains( 'registration_log', $ms_global_unprefixed );
		$this->assertNotContains( 'blog_versions', $ms_global_unprefixed, 'blog_versions is a legacy MU table, not in WP 6.7 ms_global_tables.' );

		$old_unprefixed = array_values( $wpdb->tables( 'old', false ) );
		$this->assertContains( 'categories', $old_unprefixed );
		$this->assertContains( 'post2cat', $old_unprefixed );
		$this->assertContains( 'link2cat', $old_unprefixed );

		if ( is_multisite() ) {
			$this->assertContains( 'sitecategories', $old_unprefixed, 'On multisite, old tables must include sitecategories.' );
		}
	}

	/**
	 * Verify unauthorized users are denied before any detailed source inventory is collected.
	 */
	public function test_preflight_denies_unauthorized_users_before_inventory() {
		$service = new PreflightService();
		$mode    = is_multisite() ? MigrationMode::SUBSITE_TO_STANDALONE : MigrationMode::STANDALONE_TO_SUBSITE;
		$site_id = is_multisite() ? 1 : null;

		// 1. Unauthenticated / anonymous user (ID 0).
		wp_set_current_user( 0 );
		$anon_report = $service->run( $mode, $site_id );
		$anon_array  = $anon_report->to_array();

		$this->assertSame( PreflightReport::STATUS_UNAUTHORIZED, $anon_array['status'] );
		$this->assertFalse( $anon_array['can_proceed_to_next_stage'] );
		$this->assertNull( $anon_array['source_inventory'], 'Unauthorized preflight must not return source inventory.' );
		$this->assertSame( 'SC_AUTH_FORBIDDEN', $anon_array['findings'][0]['id'] );

		// 2. Subscriber user.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$sub_report = $service->run( $mode, $site_id );
		$this->assertSame( PreflightReport::STATUS_UNAUTHORIZED, $sub_report->get_status() );
		$this->assertNull( $sub_report->to_array()['source_inventory'] );

		// 3. On multisite, a regular site administrator (manage_options on a subsite, NOT super-admin) must be denied.
		if ( is_multisite() ) {
			$site_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $site_admin_id );
			$this->assertFalse( current_user_can( 'manage_network' ) );

			$site_admin_report = $service->run( MigrationMode::SUBSITE_TO_STANDALONE, 1 );
			$this->assertSame( PreflightReport::STATUS_UNAUTHORIZED, $site_admin_report->get_status() );
			$this->assertNull( $site_admin_report->to_array()['source_inventory'] );
		}
	}

	/**
	 * Verify mode compatibility checks against current runtime topology (standalone vs multisite).
	 */
	public function test_preflight_mode_compatibility_and_target_unverified_status() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$service = new PreflightService();

		if ( is_multisite() ) {
			// Mode 4 (standalone_to_subsite) must be rejected on a multisite source.
			$mismatch = $service->run( MigrationMode::STANDALONE_TO_SUBSITE );
			$this->assertSame( PreflightReport::STATUS_BLOCKED, $mismatch->get_status() );
			$this->assertFalse( $mismatch->can_proceed_to_next_stage() );
			$this->assertTrue( $this->has_finding( $mismatch, 'SC_MODE_SOURCE_MISMATCH', PreflightFinding::SEVERITY_BLOCKER ) );

			// Mode 1 (subsite_to_standalone) with valid site ID 1 must inspect source and report target unverified.
			$valid = $service->run( MigrationMode::SUBSITE_TO_STANDALONE, 1, array( 'expected_type' => 'standalone' ) );
			$this->assertSame( PreflightReport::STATUS_SOURCE_INSPECTED, $valid->get_status() );
			$this->assertFalse( $valid->can_proceed_to_next_stage(), 'Must remain false because destination is not verified.' );
			$this->assertTrue( $this->has_finding( $valid, 'SC_TARGET_UNVERIFIED', PreflightFinding::SEVERITY_INFO ) );
		} else {
			// Modes 1, 2, 3 must be rejected on a standalone source.
			foreach ( array( MigrationMode::SUBSITE_TO_STANDALONE, MigrationMode::SUBSITE_TO_MULTISITE, MigrationMode::FULL_NETWORK_TO_NEW_ENV ) as $ms_mode ) {
				$mismatch = $service->run( $ms_mode, 1 );
				$this->assertSame( PreflightReport::STATUS_BLOCKED, $mismatch->get_status() );
				$this->assertFalse( $mismatch->can_proceed_to_next_stage() );
				$this->assertTrue( $this->has_finding( $mismatch, 'SC_MODE_SOURCE_MISMATCH', PreflightFinding::SEVERITY_BLOCKER ) );
			}

			// Mode 4 (standalone_to_subsite) succeeds as source_inspected on standalone.
			$valid = $service->run( MigrationMode::STANDALONE_TO_SUBSITE, null, array( 'expected_type' => 'multisite_subsite' ) );
			$this->assertSame( PreflightReport::STATUS_SOURCE_INSPECTED, $valid->get_status() );
			$this->assertFalse( $valid->can_proceed_to_next_stage() );
			$this->assertTrue( $this->has_finding( $valid, 'SC_TARGET_UNVERIFIED', PreflightFinding::SEVERITY_INFO ) );
		}
	}

	/**
	 * Verify source discovery on Site ID 1 vs Site ID 2, dynamic prefixes, table classification, and blog context preservation.
	 */
	public function test_source_discovery_tables_prefixes_and_context_restoration() {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$service = new PreflightService();

		if ( ! is_multisite() ) {
			$report    = $service->run( MigrationMode::STANDALONE_TO_SUBSITE );
			$inventory = $report->to_array()['source_inventory'];

			$this->assertFalse( $inventory['environment']['is_multisite'] );
			$this->assertSame( $wpdb->base_prefix, $inventory['environment']['base_prefix'] );
			$this->assertSame( 1, $inventory['target_source_site']['blog_id'] );
			$this->assertSame( $wpdb->base_prefix, $inventory['target_source_site']['blog_prefix'] );
			$this->assertArrayHasKey( 'posts', $inventory['tables']['core_blog'] );
			$this->assertTrue( $inventory['tables']['core_blog']['posts']['exists'] );
			return;
		}

		// Create a Secondary subsite (Site ID >= 2) with real physical tables (removing WP_UnitTestCase's temporary-table filter
		// so MySQL 8.0 SHOW TABLES lists the subsite and custom tables identically to MariaDB).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$subsite_id = self::factory()->blog->create(
			array(
				'domain' => 'example.org',
				'path'   => '/discovery-subsite/',
				'title'  => 'Discovery Subsite',
			)
		);

		$custom_subsite_table = $wpdb->get_blog_prefix( $subsite_id ) . 'custom_plugin_items';
		$legacy_mu_table      = $wpdb->base_prefix . 'blog_versions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$custom_subsite_table}` (id INT PRIMARY KEY)" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$legacy_mu_table}` (blog_id BIGINT(20) PRIMARY KEY, db_version VARCHAR(20))" );

		try {
			// Switch to subsite first, then inspect Site ID 1 to verify current blog context is preserved/restored!
			switch_to_blog( $subsite_id );
			$prior_blog_id = get_current_blog_id();
			$this->assertSame( $subsite_id, $prior_blog_id );

			$site1_report = $service->run( MigrationMode::SUBSITE_TO_STANDALONE, 1 );
			$this->assertSame( $subsite_id, get_current_blog_id(), 'Preflight must restore prior blog context after inspecting another site.' );

			$site1_inv = $site1_report->to_array()['source_inventory'];
			$this->assertTrue( $site1_inv['target_source_site']['is_main_site'] );
			$this->assertSame( $wpdb->base_prefix, $site1_inv['target_source_site']['blog_prefix'] );
			$this->assertArrayHasKey( 'blogmeta', $site1_inv['tables']['core_ms_global'] );
			$this->assertArrayNotHasKey( 'blogmeta', $site1_inv['tables']['core_blog'] );
			$this->assertContains( $legacy_mu_table, $site1_inv['tables']['legacy_multisite'] );
			$this->assertTrue( $this->has_finding( $site1_report, 'SC_MAIN_SITE_SHARED_PREFIX_ISOLATION', PreflightFinding::SEVERITY_INFO ) );

			// Inspect Subsite ($subsite_id) while on Site 1.
			restore_current_blog();
			$this->assertSame( 1, get_current_blog_id() );

			$sub_report = $service->run( MigrationMode::SUBSITE_TO_MULTISITE, $subsite_id );
			$this->assertSame( 1, get_current_blog_id(), 'Blog context must remain Site 1.' );

			$sub_inv = $sub_report->to_array()['source_inventory'];
			$this->assertFalse( $sub_inv['target_source_site']['is_main_site'] );
			$this->assertSame( $wpdb->get_blog_prefix( $subsite_id ), $sub_inv['target_source_site']['blog_prefix'] );
			$this->assertContains( $custom_subsite_table, $sub_inv['tables']['unknown_custom']['blog_prefix_matched'] );
			$this->assertTrue( $this->has_finding( $sub_report, 'SC_UNKNOWN_CUSTOM_TABLES_DETECTED', PreflightFinding::SEVERITY_WARNING ) );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$custom_subsite_table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_mu_table}`" );
			foreach ( $wpdb->tables( 'blog', true, $subsite_id ) as $subsite_core_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE IF EXISTS `{$subsite_core_table}`" );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Verify missing, invalid, or archived/deleted/spam subsites produce predictable findings without fatal errors.
	 */
	public function test_invalid_missing_and_non_public_site_states() {
		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$service = new PreflightService();

		// 1. Missing site ID for a subsite mode.
		$no_id = $service->run( MigrationMode::SUBSITE_TO_STANDALONE, null );
		$this->assertSame( PreflightReport::STATUS_BLOCKED, $no_id->get_status() );
		$this->assertTrue( $this->has_finding( $no_id, 'SC_SOURCE_SITE_ID_REQUIRED', PreflightFinding::SEVERITY_BLOCKER ) );

		// 2. Non-existent site ID (999999).
		$missing = $service->run( MigrationMode::SUBSITE_TO_STANDALONE, 999999 );
		$this->assertSame( PreflightReport::STATUS_BLOCKED, $missing->get_status() );
		$this->assertTrue( $this->has_finding( $missing, 'SC_SOURCE_SITE_NOT_FOUND', PreflightFinding::SEVERITY_BLOCKER ) );

		// 3. Archived subsite produces a warning finding.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$archived_blog_id = self::factory()->blog->create(
			array(
				'domain' => 'example.org',
				'path'   => '/archived-subsite/',
			)
		);

		try {
			update_blog_status( $archived_blog_id, 'archived', 1 );

			$archived_report = $service->run( MigrationMode::SUBSITE_TO_STANDALONE, $archived_blog_id );
			$this->assertTrue( $this->has_finding( $archived_report, 'SC_SOURCE_SITE_NON_STANDARD_STATUS', PreflightFinding::SEVERITY_WARNING ) );
		} finally {
			global $wpdb;
			foreach ( $wpdb->tables( 'blog', true, $archived_blog_id ) as $archived_core_table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE IF EXISTS `{$archived_core_table}`" );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Verify >100 network sites pagination does not truncate at WordPress default 100 limit.
	 */
	public function test_network_site_enumeration_paginates_beyond_100_sites() {
		$simulated_sites = array();
		for ( $i = 1; $i <= 135; $i++ ) {
			$simulated_sites[] = array(
				'blog_id'    => $i,
				'network_id' => 1,
				'domain'     => 'example.org',
				'path'       => 1 === $i ? '/' : '/site-' . $i . '/',
				'archived'   => 0,
				'spam'       => 0,
				'deleted'    => 0,
			);
		}

		$pages_requested = 0;
		$custom_fetcher  = function ( $offset, $number ) use ( $simulated_sites, &$pages_requested ) {
			++$pages_requested;
			return array_slice( $simulated_sites, $offset, $number );
		};

		$discovery = new SourceDiscoveryService( null, $custom_fetcher );
		$sites     = $discovery->enumerate_network_sites( 50 );

		$this->assertCount( 135, $sites, 'Must enumerate all 135 sites across multiple pages without truncating at 100.' );
		$this->assertSame( 3, $pages_requested, '135 sites with batch size 50 must require 3 page queries.' );
		$this->assertSame( 1, $sites[0]['blog_id'] );
		$this->assertSame( 135, $sites[134]['blog_id'] );
	}

	/**
	 * Verify custom user table and multi-network blockers are detected and reported.
	 */
	public function test_unsupported_custom_user_tables_and_multinetwork_blockers() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		// Inject simulated environment overrides into SourceDiscoveryService to test custom user table and multi-network detection cleanly.
		$discovery = new SourceDiscoveryService(
			array(
				'custom_user_table'      => 'external_shared_users',
				'custom_user_meta_table' => 'external_shared_usermeta',
				'network_count'          => 2,
			)
		);
		$service   = new PreflightService( $discovery );
		$mode      = is_multisite() ? MigrationMode::FULL_NETWORK_TO_NEW_ENV : MigrationMode::STANDALONE_TO_SUBSITE;

		$report = $service->run( $mode );
		$this->assertSame( PreflightReport::STATUS_BLOCKED, $report->get_status() );
		$this->assertFalse( $report->can_proceed_to_next_stage() );
		$this->assertTrue( $this->has_finding( $report, 'SC_CUSTOM_USER_TABLE_UNSUPPORTED', PreflightFinding::SEVERITY_BLOCKER ) );

		if ( is_multisite() ) {
			$this->assertTrue( $this->has_finding( $report, 'SC_MULTI_NETWORK_UNSUPPORTED', PreflightFinding::SEVERITY_BLOCKER ) );
		}
	}

	/**
	 * Verify no credentials, salts, password hashes, or user emails are exposed in the discovery/preflight output.
	 */
	public function test_no_secrets_or_sensitive_user_data_in_preflight_report() {
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'siteconvoy_sec_admin',
				'user_pass'  => 'SuperSecretPass!99',
				'user_email' => 'sensitive_email_check@siteconvoy-fixture.test',
			)
		);
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$service = new PreflightService();
		$mode    = is_multisite() ? MigrationMode::SUBSITE_TO_STANDALONE : MigrationMode::STANDALONE_TO_SUBSITE;
		$site_id = is_multisite() ? 1 : null;

		$report = $service->run( $mode, $site_id );
		$json   = (string) wp_json_encode( $report->to_array() );

		$this->assertStringNotContainsString( 'sensitive_email_check@siteconvoy-fixture.test', $json );
		$this->assertStringNotContainsString( 'SuperSecretPass!99', $json );
		$user = get_userdata( $admin_id );
		$this->assertStringNotContainsString( $user->user_pass, $json, 'Password hash must never appear in report.' );

		if ( defined( 'DB_PASSWORD' ) && '' !== DB_PASSWORD ) {
			$this->assertStringNotContainsString( '"db_password"', strtolower( $json ) );
		}
		if ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY ) {
			$this->assertStringNotContainsString( AUTH_KEY, $json );
		}
	}

	/**
	 * Helper to check if a report contains a specific finding ID and severity.
	 *
	 * @param PreflightReport $report   Report instance.
	 * @param string          $id       Finding ID.
	 * @param string          $severity Expected severity.
	 * @return bool
	 */
	private function has_finding( PreflightReport $report, $id, $severity ) {
		foreach ( $report->get_findings() as $finding ) {
			if ( $finding->get_id() === $id && $finding->get_severity() === $severity ) {
				return true;
			}
		}
		return false;
	}
}
