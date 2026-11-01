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
	 * Test that plugin file exists and contains valid WordPress plugin headers.
	 */
	public function test_plugin_file_and_headers() {
		$plugin_file = dirname( __DIR__ ) . '/siteconvoy.php';
		$this->assertFileExists( $plugin_file, 'siteconvoy.php must exist in root.' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $plugin_file );
		$this->assertStringContainsString( 'Plugin Name: SiteConvoy', $content );
		$this->assertStringContainsString( 'License: GPL-2.0-or-later', $content );
		$this->assertStringContainsString( 'Text Domain: siteconvoy', $content );
	}

	/**
	 * Test that bootstrap constants and functions are declared.
	 */
	public function test_bootstrap_definitions() {
		require_once dirname( __DIR__ ) . '/siteconvoy.php';

		$this->assertTrue( defined( 'SITECONVOY_VERSION' ) );
		$this->assertSame( '0.1.0-dev', SITECONVOY_VERSION );
		$this->assertTrue( function_exists( 'siteconvoy_init' ) );
	}
}
