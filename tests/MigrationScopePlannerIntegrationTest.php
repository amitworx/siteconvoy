<?php
/**
 * WordPress Integration Tests for MigrationScopePlanner.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use WP_UnitTestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Contracts\MigrationPlan;
use SiteConvoy\Contracts\PreflightReport;
use SiteConvoy\Discovery\SourceDiscoveryService;
use SiteConvoy\Planning\MigrationScopePlanner;
use SiteConvoy\Preflight\PreflightService;

/**
 * Class MigrationScopePlannerIntegrationTest
 */
class MigrationScopePlannerIntegrationTest extends WP_UnitTestCase {

	/**
	 * Test that unauthorized callers are rejected before any plan or source data is generated.
	 */
	public function test_planner_denies_unauthorized_callers() {
		$planner = new MigrationScopePlanner();
		$mode    = is_multisite() ? MigrationMode::SUBSITE_TO_STANDALONE : MigrationMode::STANDALONE_TO_SUBSITE;
		$site_id = is_multisite() ? 1 : null;

		// 1. Anonymous user.
		wp_set_current_user( 0 );
		$anon_plan = $planner->plan( $mode, $site_id );
		$this->assertSame( 'unauthorized', $anon_plan->get_status() );
		$this->assertFalse( $anon_plan->is_executable() );
		$this->assertEmpty( $anon_plan->to_array()['tables'] );

		// 2. Subscriber.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );
		$sub_plan = $planner->plan( $mode, $site_id );
		$this->assertSame( 'unauthorized', $sub_plan->get_status() );
		$this->assertFalse( $sub_plan->is_executable() );

		// 3. Subsite admin on multisite without manage_network.
		if ( is_multisite() ) {
			$subsite_admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $subsite_admin_id );
			$this->assertFalse( current_user_can( 'manage_network' ) );

			$admin_plan = $planner->plan( MigrationMode::SUBSITE_TO_STANDALONE, 1 );
			$this->assertSame( 'unauthorized', $admin_plan->get_status() );
			$this->assertFalse( $admin_plan->is_executable() );
		}
	}

	/**
	 * Test Mode 1: Subsite -> Standalone (Site ID 1 vs secondary Subsite ID 2).
	 * Verifies Site 1 tables do NOT include ms_global tables despite sharing base_prefix,
	 * and user identity tables are marked as excluded/needs_review with no row data.
	 */
	public function test_mode1_subsite_to_standalone_table_scoping() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$planner = new MigrationScopePlanner();

		// Case A: Main Site (Site ID 1).
		$plan_site1 = $planner->plan( MigrationMode::SUBSITE_TO_STANDALONE, 1 );
		$this->assertSame( 'needs_review', $plan_site1->get_status() );
		$this->assertFalse( $plan_site1->is_executable() );

		$tables_site1 = $plan_site1->get_tables();
		$this->assertNotEmpty( $tables_site1['blog'] );
		$this->assertNotEmpty( $tables_site1['global'] );
		$this->assertNotEmpty( $tables_site1['ms_global'] );

		// Ensure blog tables are included.
		$blog_table_names = array_column( $tables_site1['blog'], 'table_name' );
		$this->assertContains( $wpdb->base_prefix . 'posts', $blog_table_names );
		$this->assertContains( $wpdb->base_prefix . 'options', $blog_table_names );

		// Ensure ms_global tables are EXCLUDED, even though they share wp_ base prefix!
		$ms_global_table_names = array_column( $tables_site1['ms_global'], 'table_name' );
		$this->assertContains( $wpdb->base_prefix . 'blogs', $ms_global_table_names );
		$this->assertContains( $wpdb->base_prefix . 'site', $ms_global_table_names );
		$this->assertContains( $wpdb->base_prefix . 'sitemeta', $ms_global_table_names );
		foreach ( $tables_site1['ms_global'] as $ms_table ) {
			$this->assertSame( 'excluded', $ms_table['status'] );
			$this->assertSame( 'ms_global', $ms_table['scope'] );
		}

		// Ensure global identity tables (users, usermeta) are marked excluded / needs_review for subsite migration.
		foreach ( $tables_site1['global'] as $global_table ) {
			$this->assertSame( 'excluded', $global_table['status'] );
			$this->assertStringContainsString( 'review', strtolower( $global_table['reason'] ) );
		}

		// Case B: Secondary Subsite (Site ID >= 2).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$subsite_id = self::factory()->blog->create(
			array(
				'domain' => 'example.org',
				'path'   => '/planner-subsite/',
			)
		);

		try {
			$plan_subsite = $planner->plan( MigrationMode::SUBSITE_TO_STANDALONE, $subsite_id );
			$this->assertSame( 'needs_review', $plan_subsite->get_status() );
			$tables_subsite  = $plan_subsite->get_tables();
			$sub_blog_names  = array_column( $tables_subsite['blog'], 'table_name' );
			$expected_prefix = $wpdb->get_blog_prefix( $subsite_id );

			$this->assertContains( $expected_prefix . 'posts', $sub_blog_names );
			$this->assertNotContains( $wpdb->base_prefix . 'posts', $sub_blog_names );
		} finally {
			wp_delete_site( $subsite_id );
			foreach ( $wpdb->tables( 'blog', true, $subsite_id ) as $tbl ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE IF EXISTS `{$tbl}`" );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Test Mode 2: Subsite -> Another Multisite Network.
	 * Verifies subsite blog tables included, ms_global excluded, global identity excluded for mapping workflow.
	 */
	public function test_mode2_subsite_to_multisite_table_scoping() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$planner = new MigrationScopePlanner();
		$plan    = $planner->plan( MigrationMode::SUBSITE_TO_MULTISITE, 1 );

		$this->assertSame( 'needs_review', $plan->get_status() );
		$this->assertFalse( $plan->is_executable() );

		$tables = $plan->get_tables();
		foreach ( $tables['blog'] as $t ) {
			$this->assertSame( 'included', $t['status'] );
		}
		foreach ( $tables['ms_global'] as $t ) {
			$this->assertSame( 'excluded', $t['status'] );
		}
		foreach ( $tables['global'] as $t ) {
			$this->assertSame( 'excluded', $t['status'] );
		}
	}

	/**
	 * Test Mode 3: Whole Multisite Network -> New Environment.
	 * Verifies all network subsite blog tables + global identity + ms_global are candidate inclusions,
	 * but multi-network (>1 network in wp_site) is blocked.
	 */
	public function test_mode3_full_network_table_scoping_and_multinetwork_blocker() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$planner = new MigrationScopePlanner();
		$plan    = $planner->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );

		$this->assertSame( 'needs_review', $plan->get_status() );
		$this->assertFalse( $plan->is_executable() );

		$tables = $plan->get_tables();
		$this->assertNotEmpty( $tables['blog'] );
		$this->assertNotEmpty( $tables['global'] );
		$this->assertNotEmpty( $tables['ms_global'] );

		// In Mode 3, global identity tables (users, usermeta) are candidates, but flagged as needs_review.
		foreach ( $tables['global'] as $t ) {
			$this->assertSame( 'needs_review', $t['status'] );
			$this->assertSame( 'global', $t['scope'] );
		}
		// ms_global tables are candidates for full network.
		foreach ( $tables['ms_global'] as $t ) {
			$this->assertSame( 'included', $t['status'] );
			$this->assertSame( 'ms_global', $t['scope'] );
		}

		// Multi-network simulation: planner must be blocked.
		$discovery_override = new SourceDiscoveryService( array( 'network_count' => 2 ) );
		$preflight_override = new PreflightService( $discovery_override );
		$blocked_planner    = new MigrationScopePlanner( $preflight_override, $discovery_override );

		$blocked_plan = $blocked_planner->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );
		$this->assertSame( 'blocked', $blocked_plan->get_status() );
		$this->assertFalse( $blocked_plan->is_executable() );
	}

	/**
	 * Test Mode 4: Standalone WordPress -> Multisite Subsite.
	 * Verifies standalone blog tables are candidate inclusions, user identity requires review,
	 * and ms_global is empty/not applicable.
	 */
	public function test_mode4_standalone_to_subsite_table_scoping() {
		global $wpdb;

		if ( is_multisite() ) {
			$this->assertTrue( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$planner = new MigrationScopePlanner();
		$plan    = $planner->plan( MigrationMode::STANDALONE_TO_SUBSITE );

		$this->assertSame( 'needs_review', $plan->get_status() );
		$this->assertFalse( $plan->is_executable() );

		$tables = $plan->get_tables();
		$this->assertNotEmpty( $tables['blog'] );
		$this->assertNotEmpty( $tables['global'] );
		$this->assertEmpty( $tables['ms_global'] );

		foreach ( $tables['blog'] as $t ) {
			$this->assertSame( 'included', $t['status'] );
		}
		foreach ( $tables['global'] as $t ) {
			$this->assertSame( 'needs_review', $t['status'] );
		}
	}

	/**
	 * Test that unresolved custom tables and legacy blog_versions tables are flagged in plan output.
	 */
	public function test_unresolved_custom_tables_and_legacy_mu_in_plan() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$custom_table = $wpdb->base_prefix . 'custom_test_plugin_tbl';
		$legacy_table = $wpdb->base_prefix . 'blog_versions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$custom_table}` (id INT PRIMARY KEY)" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$legacy_table}` (blog_id BIGINT PRIMARY KEY, db_version VARCHAR(20))" );

		try {
			$planner = new MigrationScopePlanner();
			$plan    = $planner->plan( MigrationMode::SUBSITE_TO_STANDALONE, 1 );

			$tables = $plan->get_tables();
			$this->assertNotEmpty( $tables['unresolved'] );

			$unresolved_names = array_column( $tables['unresolved'], 'table_name' );
			$this->assertContains( $custom_table, $unresolved_names );
			$this->assertContains( $legacy_table, $unresolved_names );

			foreach ( $tables['unresolved'] as $entry ) {
				$this->assertSame( 'unresolved', $entry['status'] );
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$custom_table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$legacy_table}`" );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Test SourceDiscoveryService foreign network enumeration rejection (Deliverable C follow-up).
	 * Enforce that enumerate_network_sites() only permits the current network ID.
	 */
	public function test_enumerate_network_sites_rejects_foreign_network_id() {
		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$discovery = new SourceDiscoveryService();

		// Passing a foreign network ID (e.g. 999 or 2) must be rejected with InvalidArgumentException.
		$this->expectException( \InvalidArgumentException::class );
		$discovery->enumerate_network_sites( 100, 999 );
	}

	/**
	 * Test that no secrets, credentials, password hashes, or emails appear in MigrationPlan JSON.
	 */
	public function test_migration_plan_contains_no_secrets_or_pii() {
		$admin_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'plan_security_admin',
				'user_pass'  => 'SecretPasswordPlan!88',
				'user_email' => 'sensitive_plan_email@siteconvoy-fixture.test',
			)
		);
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$planner = new MigrationScopePlanner();
		$mode    = is_multisite() ? MigrationMode::SUBSITE_TO_STANDALONE : MigrationMode::STANDALONE_TO_SUBSITE;
		$site_id = is_multisite() ? 1 : null;

		$plan = $planner->plan( $mode, $site_id );
		$json = (string) wp_json_encode( $plan->to_array() );

		$this->assertStringNotContainsString( 'sensitive_plan_email@siteconvoy-fixture.test', $json );
		$this->assertStringNotContainsString( 'SecretPasswordPlan!88', $json );
		$user = get_userdata( $admin_id );
		$this->assertStringNotContainsString( $user->user_pass, $json );
	}

	/**
	 * Test full-network plan with simulated >100 sites without truncation or duplicate shared tables.
	 */
	public function test_full_network_plan_paginated_site_coverage() {
		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$simulated_sites = array();
		for ( $i = 1; $i <= 120; $i++ ) {
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

		$fetcher = function ( $offset, $number, $network_id = 1 ) use ( $simulated_sites ) {
			unset( $network_id );
			return array_slice( $simulated_sites, $offset, $number );
		};

		// 1. Without physical tables, mocked 120 sites must fail closed with status 'blocked' and missing tables.
		$discovery_unmocked = new SourceDiscoveryService( null, $fetcher );
		$preflight_unmocked = new PreflightService( $discovery_unmocked );
		$planner_unmocked   = new MigrationScopePlanner( $preflight_unmocked, $discovery_unmocked );
		$plan_unmocked      = $planner_unmocked->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );

		$this->assertSame( 'blocked', $plan_unmocked->get_status(), '120 mocked sites without physical tables must fail closed (blocked).' );
		$this->assertFalse( $plan_unmocked->is_executable() );

		// 2. When physical tables are present in catalog, planner produces a valid 'needs_review' plan with 120 sites.
		$mock_physical = array();
		global $wpdb;
		// Include ms_global and global tables.
		foreach ( $wpdb->tables( 'ms_global' ) as $t ) {
			$mock_physical[] = $t;
		}
		foreach ( $wpdb->tables( 'global' ) as $t ) {
			$mock_physical[] = $t;
		}
		// Include blog tables for all 120 sites.
		for ( $i = 1; $i <= 120; $i++ ) {
			foreach ( $wpdb->tables( 'blog', true, $i ) as $t ) {
				$mock_physical[] = $t;
			}
		}

		$discovery = new SourceDiscoveryService( array( 'physical_tables' => $mock_physical ), $fetcher );
		$preflight = new PreflightService( $discovery );
		$planner   = new MigrationScopePlanner( $preflight, $discovery );

		$plan = $planner->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );
		$this->assertSame( 'needs_review', $plan->get_status() );

		$data = $plan->to_array();
		$this->assertSame( 120, $data['source_topology']['site_count'] );
		$this->assertCount( 120, $data['source_topology']['network_sites'] );

		// Global identity tables (users, usermeta) must appear exactly once in global array, not duplicated per site.
		$global_tables = $plan->get_tables()['global'];
		$global_names  = array_column( $global_tables, 'logical_name' );
		$this->assertCount( 2, $global_names );
		$this->assertSame( array_unique( $global_names ), $global_names );
	}
	/**
	 * Test that full-network planning fails closed (status: blocked) when required core tables
	 * for any network site or core ms_global are physically absent, marking them 'missing'.
	 */
	public function test_full_network_plan_fails_closed_when_physical_tables_are_missing() {
		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		// Simulate 2 sites where site 2 does NOT have physical blog tables created in DB.
		$simulated_sites = array(
			array(
				'blog_id'    => 1,
				'network_id' => 1,
				'domain'     => 'example.org',
				'path'       => '/',
				'archived'   => 0,
				'spam'       => 0,
				'deleted'    => 0,
			),
			array(
				'blog_id'    => 9999, // Non-existent site with no physical tables in DB.
				'network_id' => 1,
				'domain'     => 'example.org',
				'path'       => '/missing-site/',
				'archived'   => 0,
				'spam'       => 0,
				'deleted'    => 0,
			),
		);

		$fetcher = function ( $offset, $number, $network_id = 1 ) use ( $simulated_sites ) {
			unset( $network_id );
			return array_slice( $simulated_sites, $offset, $number );
		};

		$discovery = new SourceDiscoveryService( null, $fetcher );
		$preflight = new PreflightService( $discovery );
		$planner   = new MigrationScopePlanner( $preflight, $discovery );

		$plan = $planner->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );

		// The plan must be fail-closed (status 'blocked') because site 9999 tables are physically absent.
		$this->assertSame( 'blocked', $plan->get_status() );
		$this->assertFalse( $plan->is_executable() );

		// Check that blog tables for site 9999 are marked 'missing'.
		$tables = $plan->get_tables();
		$this->assertNotEmpty( $tables['blog'] );

		$missing_found = false;
		foreach ( $tables['blog'] as $t ) {
			if ( false !== strpos( $t['table_name'], '9999' ) ) {
				$this->assertSame( 'missing', $t['status'] );
				$missing_found = true;
			}
		}
		$this->assertTrue( $missing_found, 'Absent site tables must be classified as missing.' );
	}

	/**
	 * Test that physically existing legacy core_old tables (such as sitecategories or categories)
	 * are surfaced in the plan under 'unresolved' as 'needs_review' rather than silently omitted.
	 */
	public function test_physically_existing_core_old_tables_surfaced_in_plan() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$old_table = $wpdb->base_prefix . 'sitecategories';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$old_table}` (cat_id BIGINT PRIMARY KEY, cat_name VARCHAR(55))" );

		try {
			$planner = new MigrationScopePlanner();
			$plan    = $planner->plan( MigrationMode::SUBSITE_TO_STANDALONE, 1 );

			$tables = $plan->get_tables();
			$this->assertNotEmpty( $tables['unresolved'] );

			$unresolved_tables = array_column( $tables['unresolved'], 'table_name' );
			$this->assertContains( $old_table, $unresolved_tables );

			foreach ( $tables['unresolved'] as $entry ) {
				if ( $entry['table_name'] === $old_table ) {
					$this->assertSame( 'needs_review', $entry['status'] );
					$this->assertSame( 'core_old', $entry['scope'] );
				}
			}
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$old_table}`" );
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Test that physically existing legacy old tables on a secondary subsite are included
	 * exactly once in unresolved review for full-network planning with site provenance,
	 * without duplicate ms_global entries or misclassification into custom tables.
	 */
	public function test_full_network_plan_surfaces_subsite_core_old_tables_in_review() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$subsite_id        = self::factory()->blog->create();
		$subsite_prefix    = $wpdb->get_blog_prefix( $subsite_id );
		$subsite_old_table = $subsite_prefix . 'categories';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$subsite_old_table}` (cat_id BIGINT PRIMARY KEY, cat_name VARCHAR(55))" );

		try {
			$planner = new MigrationScopePlanner();
			$plan    = $planner->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );

			$tables = $plan->get_tables();
			$this->assertNotEmpty( $tables['unresolved'] );

			$subsite_old_entries = array();
			foreach ( $tables['unresolved'] as $entry ) {
				if ( $entry['table_name'] === $subsite_old_table ) {
					$subsite_old_entries[] = $entry;
				}
			}

			// Must appear exactly once in unresolved tables.
			$this->assertCount( 1, $subsite_old_entries, 'Subsite old table must appear exactly once in unresolved review manifest.' );

			$entry = $subsite_old_entries[0];
			$this->assertSame( 'needs_review', $entry['status'] );
			$this->assertSame( 'core_old', $entry['scope'] );
			$this->assertSame( 'categories', $entry['logical_name'] );
			$this->assertSame( $subsite_id, $entry['site_id'] );
			$this->assertStringContainsString( 'site ID ' . $subsite_id, $entry['reason'] );

			// Must not be misclassified as custom table.
			$custom_tables = array();
			foreach ( $tables['unresolved'] as $entry ) {
				if ( 'custom' === $entry['scope'] ) {
					$custom_tables[] = $entry['table_name'];
				}
			}
			$this->assertNotContains( $subsite_old_table, $custom_tables, 'Subsite old table must not be misclassified as a custom table.' );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$subsite_old_table}`" );
			wp_delete_site( $subsite_id );
			$subsite_tables = $wpdb->tables( 'blog', true, $subsite_id );
			foreach ( $subsite_tables as $st ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DROP TABLE IF EXISTS `{$st}`" );
			}
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Test that missing global identity tables (users, usermeta) fail closed and are reported
	 * in SC_MISSING_CORE_TABLES_PLAN blocker finding with correct table name and count.
	 */
	public function test_missing_global_identity_tables_included_in_blocker_finding() {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		// Simulate physical tables catalog missing 'wp_users' without dropping the real DB table.
		$real_tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$users_table = $wpdb->users;

		// Filter out users table from physical list.
		$mocked_physical = array_values( array_diff( $real_tables, array( $users_table ) ) );

		$discovery = new SourceDiscoveryService( array( 'physical_tables' => $mocked_physical ) );
		$preflight = new PreflightService( $discovery );
		$planner   = new MigrationScopePlanner( $preflight, $discovery );

		$mode = is_multisite() ? MigrationMode::FULL_NETWORK_TO_NEW_ENV : MigrationMode::STANDALONE_TO_SUBSITE;
		$plan = $planner->plan( $mode );

		$this->assertSame( 'blocked', $plan->get_status() );
		$this->assertFalse( $plan->is_executable() );

		// Verify that global tables has status 'missing'.
		$tables = $plan->get_tables();
		$this->assertNotEmpty( $tables['global'] );

		$users_entry = null;
		foreach ( $tables['global'] as $entry ) {
			if ( $entry['table_name'] === $users_table ) {
				$users_entry = $entry;
				break;
			}
		}

		$this->assertNotNull( $users_entry, 'Users table entry must exist in global tables.' );
		$this->assertSame( 'missing', $users_entry['status'] );

		// Check the preflight report blocker finding.
		$preflight_report = $plan->get_preflight_report();
		$this->assertNotNull( $preflight_report );
		$findings = $preflight_report->get_findings();

		$missing_finding = null;
		foreach ( $findings as $b ) {
			if ( 'SC_MISSING_CORE_TABLES_PLAN' === $b->get_id() ) {
				$missing_finding = $b;
				break;
			}
		}

		$this->assertNotNull( $missing_finding, 'SC_MISSING_CORE_TABLES_PLAN finding must be present.' );
		$context = $missing_finding->get_context();
		$this->assertArrayHasKey( 'missing_tables', $context );
		$this->assertContains( $users_table, $context['missing_tables'], 'Missing users table must be in missing_tables list.' );
		$this->assertSame( count( $context['missing_tables'] ), $context['total_missing'] );
		$this->assertStringContainsString( $users_table, $missing_finding->get_message() );
	}

	/**
	 * Test that MU-plugins and per-site dependencies are discovered and included in plan.
	 */
	public function test_mu_plugins_and_per_site_dependencies_in_plan() {
		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $admin_id );
		wp_set_current_user( $admin_id );

		$subsite_id = self::factory()->blog->create();

		// Set child theme and a plugin on subsite.
		switch_to_blog( $subsite_id );
		update_option( 'stylesheet', 'twentytwentyfour-child' );
		update_option( 'template', 'twentytwentyfour' );
		update_option( 'active_plugins', array( 'site-specific-plugin/site-specific.php' ) );
		restore_current_blog();

		$planner = new MigrationScopePlanner();
		$plan    = $planner->plan( MigrationMode::FULL_NETWORK_TO_NEW_ENV );

		try {
			$deps = $plan->get_dependencies();
			$this->assertArrayHasKey( 'mu_plugins', $deps );
			$this->assertArrayHasKey( 'per_site', $deps );
			$this->assertArrayHasKey( $subsite_id, $deps['per_site'] );
			$this->assertSame( 'twentytwentyfour-child', $deps['per_site'][ $subsite_id ]['theme']['stylesheet'] );
			$this->assertTrue( $deps['per_site'][ $subsite_id ]['theme']['is_child'] );
			$this->assertContains( 'site-specific-plugin/site-specific.php', $deps['per_site'][ $subsite_id ]['plugins'] );
		} finally {
			wp_delete_site( $subsite_id );
		}
	}
}
