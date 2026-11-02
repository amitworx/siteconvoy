<?php
/**
 * PHPUnit bootstrap file for SiteConvoy WordPress integration tests.
 *
 * Requires a real WordPress test library and database connection. Fails closed
 * if the WordPress test suite is unavailable so missing infrastructure is never
 * misrepresented as passing integration tests.
 *
 * @package SiteConvoy
 */

// Load Composer autoloader first.
if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

require_once __DIR__ . '/JsonSchemaValidator.php';

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

if ( ! file_exists( $siteconvoy_tests_dir . '/includes/functions.php' ) ) {
	throw new \RuntimeException(
		sprintf(
			"WordPress integration test suite not found at '%s/includes/functions.php'. " .
			"Run integration tests inside wp-env ('npm run test:wp') or set WP_TESTS_DIR to a valid WordPress test library. " .
			"For standalone unit tests without WordPress, run 'composer run test:unit'.",
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			$siteconvoy_tests_dir
		)
	);
}

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
