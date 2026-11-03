<?php
/**
 * Plugin Name: SiteConvoy
 * Plugin URI: https://github.com/amitworx/siteconvoy
 * Description: WordPress multisite migration plugin.
 * Version: 0.1.0-dev
 * Requires at least: 6.7
 * Requires PHP: 8.1
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

// Load read-only contracts, source discovery, and preflight classes.
require_once SITECONVOY_PATH . 'includes/Contracts/MigrationMode.php';
require_once SITECONVOY_PATH . 'includes/Contracts/PreflightFinding.php';
require_once SITECONVOY_PATH . 'includes/Contracts/PreflightReport.php';
require_once SITECONVOY_PATH . 'includes/Contracts/MigrationPlan.php';
require_once SITECONVOY_PATH . 'includes/Discovery/SourceDiscoveryService.php';
require_once SITECONVOY_PATH . 'includes/Preflight/PreflightService.php';
require_once SITECONVOY_PATH . 'includes/Planning/MigrationScopePlanner.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageManifestValidator.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageManifest.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageVerificationResult.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageStreamVerifier.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageArchiveWriterResult.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageArchiveWriter.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageArchiveInspectionResult.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageArchiveInspector.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageArchiveStagingResult.php';
require_once SITECONVOY_PATH . 'includes/Package/PackageArchiveStager.php';

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
