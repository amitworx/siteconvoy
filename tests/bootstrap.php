<?php
/**
 * PHPUnit bootstrap file for SiteConvoy WordPress integration tests.
 *
 * @package SiteConvoy
 */

// Load Composer autoloader first.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

// Determine the WordPress test library directory.
$siteconvoy_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $siteconvoy_tests_dir ) {
	$siteconvoy_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $siteconvoy_tests_dir . '/includes/functions.php' ) ) {
	// Fallbacks inside wp-env container tests environment.
	if ( file_exists( '/wordpress-phpunit/includes/functions.php' ) ) {
		$siteconvoy_tests_dir = '/wordpress-phpunit';
	} elseif ( file_exists( '/wordpress-tests-lib/includes/functions.php' ) ) {
		$siteconvoy_tests_dir = '/wordpress-tests-lib';
	}
}

// Give access to tests_add_filter() function when running inside the WordPress test suite.
if ( file_exists( $siteconvoy_tests_dir . '/includes/functions.php' ) ) {
	require_once $siteconvoy_tests_dir . '/includes/functions.php';

	/**
	 * Manually load the plugin being tested.
	 */
	tests_add_filter(
		'muplugins_loaded',
		function () {
			require dirname( __DIR__ ) . '/siteconvoy.php';
		}
	);

	// Start up the WP testing environment.
	require_once $siteconvoy_tests_dir . '/includes/bootstrap.php';
} elseif ( ! class_exists( 'WP_UnitTestCase' ) ) {
	/**
	 * Fallback base class to allow standalone unit tests when WP test suite is not present.
	 */
	class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {
	}
}
