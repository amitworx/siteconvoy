<?php
/**
 * Plugin Name: SiteConvoy
 * Plugin URI: https://github.com/amitworx/siteconvoy
 * Description: WordPress multisite migration plugin.
 * Version: 0.1.0-dev
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Amit Sharma
 * Author URI: https://github.com/amitworx
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: siteconvoy
 * Network: true
 *
 * @package SiteConvoy
 */

// Direct access protection.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define core constants.
if ( ! defined( 'SITECONVOY_VERSION' ) ) {
	define( 'SITECONVOY_VERSION', '0.1.0-dev' );
}

if ( ! defined( 'SITECONVOY_FILE' ) ) {
	define( 'SITECONVOY_FILE', __FILE__ );
}

if ( ! defined( 'SITECONVOY_PATH' ) ) {
	define( 'SITECONVOY_PATH', plugin_dir_path( __FILE__ ) );
}

/**
 * Inert plugin initialization hook.
 *
 * This bootstrap is intentionally inert: no database modifications, no background tasks,
 * and no migration engine runtime code.
 *
 * @return void
 */
function siteconvoy_init() {
	// Pre-release inert placeholder. Migration engine scheduled for Milestone M2+.
}
add_action( 'plugins_loaded', 'siteconvoy_init' );
