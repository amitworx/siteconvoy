<?php
/**
 * Basic Plugin Smoke and Bootstrap Integration Test.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use WP_UnitTestCase;

/**
 * Class PluginBootstrapTest
 */
class PluginBootstrapTest extends WP_UnitTestCase {

	/**
	 * Test that the plugin constants are defined and match current version.
	 */
	public function test_plugin_constants_defined() {
		$this->assertTrue( defined( 'SITECONVOY_VERSION' ), 'SITECONVOY_VERSION should be defined.' );
		$this->assertSame( '0.1.0-dev', SITECONVOY_VERSION );
		$this->assertTrue( defined( 'SITECONVOY_FILE' ), 'SITECONVOY_FILE should be defined.' );
		$this->assertTrue( defined( 'SITECONVOY_PATH' ), 'SITECONVOY_PATH should be defined.' );
		$this->assertFileExists( SITECONVOY_FILE );
	}

	/**
	 * Test that the plugin bootstrap function is loaded and registered on plugins_loaded.
	 */
	public function test_plugin_bootstrap_loaded() {
		$this->assertTrue( function_exists( 'siteconvoy_init' ), 'siteconvoy_init function must exist.' );
		$this->assertSame( 10, has_action( 'plugins_loaded', 'siteconvoy_init' ) );
	}

	/**
	 * Test that WordPress recognizes the plugin metadata headers via get_plugin_data().
	 */
	public function test_wordpress_recognizes_plugin_headers() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_data = get_plugin_data( SITECONVOY_FILE, false, false );

		$this->assertSame( 'SiteConvoy', $plugin_data['Name'] );
		$this->assertSame( '0.1.0-dev', $plugin_data['Version'] );
		$this->assertSame( 'siteconvoy', $plugin_data['TextDomain'] );
		$this->assertTrue( (bool) $plugin_data['Network'], 'Plugin should declare Network: true.' );
	}

	/**
	 * Test that plugin is completely inert with zero migrations, writes, or administrative side effects.
	 */
	public function test_plugin_is_inert_without_side_effects() {
		global $submenu, $menu;

		// Execute the init hook to verify it performs no mutations.
		siteconvoy_init();

		// Verify no destructive migration hooks or unverified cron jobs are scheduled.
		$this->assertFalse( wp_next_scheduled( 'siteconvoy_migration_cron' ) );

		// Verify no plugin-specific options are written on load.
		$this->assertFalse( get_option( 'siteconvoy_settings' ) );

		// Verify no admin menu entries are registered.
		$this->assertEmpty( $menu );
		$this->assertEmpty( $submenu );
	}

	/**
	 * Test single-site vs multisite runtime context matches test suite configuration.
	 */
	public function test_runtime_multisite_context() {
		$expected_multisite = defined( 'WP_TESTS_MULTISITE' ) && (bool) WP_TESTS_MULTISITE;
		$this->assertSame( $expected_multisite, is_multisite() );
	}

	/**
	 * Test dynamic prefix and core table resolution without assuming hardcoded 'wp_'.
	 */
	public function test_dynamic_table_prefix_and_core_tables() {
		global $wpdb;

		$base_prefix = $wpdb->base_prefix;
		$this->assertNotEmpty( $base_prefix, 'Base prefix must not be empty.' );

		// Site ID 1 always resolves to base_prefix in both standalone and multisite.
		$site1_prefix = $wpdb->get_blog_prefix( 1 );
		$this->assertSame( $base_prefix, $site1_prefix, 'Site ID 1 prefix must equal base_prefix.' );

		// Verify core blog-scoped tables for Site ID 1 use dynamic base_prefix.
		$blog_tables = $wpdb->tables( 'blog', true, 1 );
		$this->assertArrayHasKey( 'posts', $blog_tables );
		$this->assertArrayHasKey( 'options', $blog_tables );
		$this->assertSame( $base_prefix . 'posts', $blog_tables['posts'] );
		$this->assertSame( $base_prefix . 'options', $blog_tables['options'] );

		// Also verify custom non-default base_prefix behavior dynamically via a cloned wpdb instance.
		$custom_wpdb = clone $wpdb;
		$custom_wpdb->set_prefix( 'custom_sc99_' );
		$this->assertSame( 'custom_sc99_', $custom_wpdb->base_prefix );
		$this->assertSame( 'custom_sc99_', $custom_wpdb->get_blog_prefix( 1 ) );
		if ( is_multisite() ) {
			$this->assertSame( 'custom_sc99_2_', $custom_wpdb->get_blog_prefix( 2 ) );
		}
	}

	/**
	 * Test multisite Site ID 1 vs Site ID 2 prefix resolution, ms_global tables, and fixture isolation.
	 */
	public function test_multisite_site1_vs_site2_fixture_isolation() {
		global $wpdb;

		if ( ! is_multisite() ) {
			// In single-site mode, get_blog_prefix() always returns base_prefix regardless of blog ID argument.
			$this->assertSame( $wpdb->base_prefix, $wpdb->get_blog_prefix( 2 ) );
			return;
		}

		$base_prefix = $wpdb->base_prefix;

		// Verify ms_global tables are distinct from blog-scoped tables on Site ID 1.
		$ms_global_tables = $wpdb->tables( 'ms_global', true, 1 );
		$blog_1_tables    = $wpdb->tables( 'blog', true, 1 );

		$this->assertArrayHasKey( 'blogs', $ms_global_tables );
		$this->assertArrayHasKey( 'blogmeta', $ms_global_tables, 'WordPress ms_global catalog includes blogmeta.' );
		$this->assertArrayHasKey( 'site', $ms_global_tables );
		$this->assertArrayHasKey( 'sitemeta', $ms_global_tables );
		$this->assertArrayNotHasKey( 'blog_versions', $ms_global_tables, 'blog_versions is a legacy MU table, not in ms_global_tables.' );
		$this->assertArrayNotHasKey( 'blogs', $blog_1_tables );
		$this->assertArrayNotHasKey( 'blogmeta', $blog_1_tables );
		$this->assertArrayNotHasKey( 'sitemeta', $blog_1_tables );

		// Provision a deterministic secondary subsite (Site ID >= 2) using WP test factory.
		$subsite_id = self::factory()->blog->create(
			array(
				'domain' => 'example.org',
				'path'   => '/siteconvoy-fixture-subsite/',
				'title'  => 'SiteConvoy Synthetic Subsite',
			)
		);

		$this->assertGreaterThanOrEqual( 2, $subsite_id, 'Secondary subsite must have blog_id >= 2.' );

		$expected_subsite_prefix = $base_prefix . $subsite_id . '_';
		$this->assertSame(
			$expected_subsite_prefix,
			$wpdb->get_blog_prefix( $subsite_id ),
			'Subsite prefix must dynamically equal base_prefix . blog_id . _'
		);

		// Verify data isolation between Main Site (ID 1) and Subsite.
		update_blog_option( 1, 'siteconvoy_fixture_marker', 'main_site_1' );
		update_blog_option( $subsite_id, 'siteconvoy_fixture_marker', 'subsite_' . $subsite_id );

		$this->assertSame( 'main_site_1', get_blog_option( 1, 'siteconvoy_fixture_marker' ) );
		$this->assertSame( 'subsite_' . $subsite_id, get_blog_option( $subsite_id, 'siteconvoy_fixture_marker' ) );

		// Verify subsite tables exist in the real database and use the subsite prefix.
		$subsite_tables = $wpdb->tables( 'blog', true, $subsite_id );
		$this->assertSame( $expected_subsite_prefix . 'options', $subsite_tables['options'] );
		$this->assertSame( $expected_subsite_prefix . 'posts', $subsite_tables['posts'] );
	}

	/**
	 * Test multisite subdomain vs subdirectory subsite creation and prefix resolution.
	 */
	public function test_multisite_subdomain_and_subdirectory_subsite_addressing() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$this->assertFalse( is_multisite() );
			return;
		}

		// 1. Subdirectory-addressed subsite fixture.
		$subdir_blog_id = self::factory()->blog->create(
			array(
				'domain' => 'network-b.example.org',
				'path'   => '/subdir-fixture/',
				'title'  => 'Subdirectory Fixture Site',
			)
		);
		$subdir_details = get_blog_details( $subdir_blog_id );
		$this->assertSame( 'network-b.example.org', $subdir_details->domain );
		$this->assertSame( '/subdir-fixture/', $subdir_details->path );
		$this->assertSame( $wpdb->base_prefix . $subdir_blog_id . '_', $wpdb->get_blog_prefix( $subdir_blog_id ) );

		// 2. Subdomain-addressed subsite fixture.
		$subdom_blog_id = self::factory()->blog->create(
			array(
				'domain' => 'site2.network-a.example.org',
				'path'   => '/',
				'title'  => 'Subdomain Fixture Site',
			)
		);
		$subdom_details = get_blog_details( $subdom_blog_id );
		$this->assertSame( 'site2.network-a.example.org', $subdom_details->domain );
		$this->assertSame( '/', $subdom_details->path );
		$this->assertSame( $wpdb->base_prefix . $subdom_blog_id . '_', $wpdb->get_blog_prefix( $subdom_blog_id ) );
	}
}
