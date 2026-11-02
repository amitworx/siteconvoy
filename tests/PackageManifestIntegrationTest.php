<?php
/**
 * Package Manifest and Runtime Boundary Integration Test.
 *
 * Verifies class bootstrap, non-executable boundary, and zero persistence side-effects
 * in a real WordPress runtime environment.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use WP_UnitTestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Package\PackageManifest;
use SiteConvoy\Package\PackageManifestValidator;
use SiteConvoy\Package\PackageStreamVerifier;
use SiteConvoy\Package\PackageVerificationResult;

/**
 * Class PackageManifestIntegrationTest
 */
class PackageManifestIntegrationTest extends WP_UnitTestCase {

	/**
	 * Test that all Package classes are loaded into the WordPress runtime and can be instantiated.
	 */
	public function test_package_classes_bootstrap_in_wordpress() {
		$this->assertTrue( class_exists( PackageManifest::class ), 'PackageManifest must be autoloaded.' );
		$this->assertTrue( class_exists( PackageManifestValidator::class ), 'PackageManifestValidator must be autoloaded.' );
		$this->assertTrue( class_exists( PackageStreamVerifier::class ), 'PackageStreamVerifier must be autoloaded.' );
		$this->assertTrue( class_exists( PackageVerificationResult::class ), 'PackageVerificationResult must be autoloaded.' );
	}

	/**
	 * Test that constructing manifests and verifying streams causes zero database mutations or options writes.
	 */
	public function test_package_manifest_and_verifier_have_zero_persistence() {
		global $wpdb;

		$initial_options_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$initial_posts_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$content = 'Sample SQL database content';
		$entries = array(
			array(
				'path'   => 'database/posts.sql',
				'size'   => strlen( $content ),
				'sha256' => hash( 'sha256', $content ),
				'type'   => 'regular',
			),
		);

		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'network_id'   => null,
				'base_prefix'  => $wpdb->base_prefix,
			),
			$entries
		);

		// Stream verification.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $stream, $content );
		rewind( $stream );

		$verifier = new PackageStreamVerifier();
		$result   = $verifier->verify( $manifest, array( 'database/posts.sql' => $stream ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 1, $result->get_verified_count() );

		// Assert zero database modifications.
		$final_options_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$final_posts_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$this->assertSame( $initial_options_count, $final_options_count, 'No options may be written during manifest verification.' );
		$this->assertSame( $initial_posts_count, $final_posts_count, 'No posts may be created during manifest verification.' );
	}

	/**
	 * Test non-executable boundary: manifest verification does not authorize execution or create MigrationPlan.
	 */
	public function test_non_executable_boundary_in_wordpress_runtime() {
		// Verify manifest does not expose execution properties or execute methods.
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'network_id'   => null,
				'base_prefix'  => 'wp_',
			),
			array(
				array(
					'path'   => 'database/schema.sql',
					'size'   => 0,
					'sha256' => hash( 'sha256', '' ),
				),
			)
		);

		$this->assertFalse( method_exists( $manifest, 'execute' ) );
		$this->assertFalse( method_exists( $manifest, 'apply' ) );
		$this->assertFalse( method_exists( $manifest, 'is_executable' ) );

		// Ensure no cron hooks are registered.
		$this->assertFalse( wp_next_scheduled( 'siteconvoy_package_import' ) );
		$this->assertFalse( wp_next_scheduled( 'siteconvoy_package_verify' ) );
	}
}
