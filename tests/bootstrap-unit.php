<?php
/**
 * PHPUnit bootstrap file for SiteConvoy standalone unit tests.
 *
 * Does not load WordPress core or database; provides minimal stubs only when
 * running outside the WordPress integration test suite.
 *
 * @package SiteConvoy
 */

if ( file_exists( dirname( __DIR__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
}

require_once __DIR__ . '/JsonSchemaValidator.php';

if ( ! function_exists( 'plugin_dir_path' ) ) {
	/**
	 * Minimal stub for plugin_dir_path() during standalone unit tests.
	 *
	 * @param string $file Filename of the plugin.
	 * @return string Trailingslashed directory path.
	 */
	function plugin_dir_path( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return rtrim( dirname( $file ), '/\\' ) . '/';
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Minimal stub for add_action() during standalone unit tests.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback function.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return bool True.
	 */
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['siteconvoy_unit_registered_actions'][ $hook_name ][] = array(
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal stub for wp_json_encode() during standalone unit tests.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth   Depth.
	 * @return string|false JSON string or false.
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		return json_encode( $data, $options, $depth );
	}
}
