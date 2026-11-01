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

		$discovery = new SourceDiscoveryService( null, $fetcher );
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
}
