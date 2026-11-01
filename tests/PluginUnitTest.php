<?php
/**
 * Plugin Unit Smoke Test.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Class PluginUnitTest
 */
class PluginUnitTest extends TestCase {

	/**
	 * Test that plugin file exists and contains valid WordPress plugin headers and direct-access guard.
	 */
	public function test_plugin_file_and_headers() {
		$plugin_file = dirname( __DIR__ ) . '/siteconvoy.php';
		$this->assertFileExists( $plugin_file, 'siteconvoy.php must exist in root.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $plugin_file );
		$this->assertStringContainsString( 'Plugin Name: SiteConvoy', $content );
		$this->assertStringContainsString( 'License: GPL-2.0-or-later', $content );
		$this->assertStringContainsString( 'Text Domain: siteconvoy', $content );
		$this->assertStringContainsString( 'Requires at least: 6.7', $content );
		$this->assertStringContainsString( 'Requires PHP: 8.1', $content );
	}

	/**
	 * Test that siteconvoy.php contains an active fail-closed direct-access guard before any definitions.
	 */
	public function test_direct_access_guard_present_before_definitions() {
		$plugin_file = dirname( __DIR__ ) . '/siteconvoy.php';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = (string) file_get_contents( $plugin_file );

		$guard_pos  = strpos( $content, "if ( ! defined( 'ABSPATH' ) )" );
		$exit_pos   = strpos( $content, 'exit;' );
		$define_pos = strpos( $content, "define( 'SITECONVOY_VERSION'" );

		$this->assertNotFalse( $guard_pos, 'Direct-access ABSPATH check must exist in siteconvoy.php.' );
		$this->assertNotFalse( $exit_pos, 'exit statement must exist in siteconvoy.php.' );
		$this->assertNotFalse( $define_pos, 'SITECONVOY_VERSION definition must exist in siteconvoy.php.' );
		$this->assertLessThan( $exit_pos, $guard_pos, 'ABSPATH guard must precede exit.' );
		$this->assertLessThan( $define_pos, $exit_pos, 'Direct-access exit must precede constant definitions.' );
	}

	/**
	 * Test that bootstrap constants and functions are declared when ABSPATH is defined.
	 */
	public function test_bootstrap_definitions() {
		require_once dirname( __DIR__ ) . '/siteconvoy.php';

		$this->assertTrue( defined( 'SITECONVOY_VERSION' ), 'SITECONVOY_VERSION must be defined.' );
		$this->assertSame( '0.1.0-dev', SITECONVOY_VERSION );
		$this->assertTrue( defined( 'SITECONVOY_FILE' ), 'SITECONVOY_FILE must be defined.' );
		$this->assertTrue( defined( 'SITECONVOY_PATH' ), 'SITECONVOY_PATH must be defined.' );
		$this->assertTrue( function_exists( 'siteconvoy_init' ), 'siteconvoy_init() must be declared.' );
	}

	/**
	 * Test fail-closed fixture provisioning context guards reject unsafe execution and accept valid local test context.
	 */
	public function test_fixture_provisioning_fail_closed_guards() {
		require_once dirname( __DIR__ ) . '/bin/setup-fixtures.php';

		// 1. Reject when explicit opt-in is missing, even on local/localhost.
		$missing_opt_in = siteconvoy_validate_fixture_context( array(), false, 'local', 'localhost', 'localhost' );
		$this->assertFalse( $missing_opt_in['allowed'] );
		$this->assertStringContainsString( 'explicit opt-in', $missing_opt_in['reason'] );

		// 2. Reject when environment type is production or staging, even with explicit opt-in.
		$prod_env = siteconvoy_validate_fixture_context( array( 'allow-test-fixtures' ), false, 'production', 'localhost', 'localhost' );
		$this->assertFalse( $prod_env['allowed'] );
		$this->assertStringContainsString( '"production" environment', $prod_env['reason'] );

		$staging_env = siteconvoy_validate_fixture_context( array( 'allow-test-fixtures' ), true, 'staging', 'localhost', 'localhost' );
		$this->assertFalse( $staging_env['allowed'] );
		$this->assertStringContainsString( '"staging" environment', $staging_env['reason'] );

		// 3. Reject when host is a non-local / non-reserved public domain, even with opt-in and local env type.
		$unsafe_host = siteconvoy_validate_fixture_context( array( 'allow-test-fixtures' ), false, 'local', 'customer-site.com', 'customer-site.com' );
		$this->assertFalse( $unsafe_host['allowed'] );
		$this->assertStringContainsString( 'non-local/non-test host', $unsafe_host['reason'] );

		// 4. Accept when opt-in is present, env is local/development/test, and host is localhost or .local/.test.
		$valid_local = siteconvoy_validate_fixture_context( array( 'allow-test-fixtures' ), false, 'local', 'localhost', 'localhost' );
		$this->assertTrue( $valid_local['allowed'] );
		$this->assertSame( '', $valid_local['reason'] );

		$valid_test_domain = siteconvoy_validate_fixture_context( array(), true, 'test', 'network-a.local', 'site2.network-a.test' );
		$this->assertTrue( $valid_test_domain['allowed'] );
	}
}
