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
use SiteConvoy\Package\PackageArchiveWriter;
use SiteConvoy\Package\PackageArchiveWriterResult;
use SiteConvoy\Package\PackageArchiveInspector;
use SiteConvoy\Package\PackageArchiveInspectionResult;

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
		$this->assertTrue( class_exists( PackageArchiveWriter::class ), 'PackageArchiveWriter must be autoloaded.' );
		$this->assertTrue( class_exists( PackageArchiveWriterResult::class ), 'PackageArchiveWriterResult must be autoloaded.' );
		$this->assertTrue( class_exists( PackageArchiveInspector::class ), 'PackageArchiveInspector must be autoloaded.' );
		$this->assertTrue( class_exists( PackageArchiveInspectionResult::class ), 'PackageArchiveInspectionResult must be autoloaded.' );
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

	/**
	 * Test that writing a synthetic package in the WordPress runtime leaves zero database traces.
	 */
	public function test_package_archive_writer_has_zero_persistence() {
		global $wpdb;

		$initial_options_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$initial_posts_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$temp_dir = sys_get_temp_dir() . '/sc_wp_int_' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $temp_dir, 0777, true );

		$destination_zip = $temp_dir . '/output.zip';
		$staging_dir     = $temp_dir . '/staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0777, true );

		$content  = 'Synthetic SQL database dump for integration test.';
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'network_id'   => null,
				'base_prefix'  => $wpdb->base_prefix,
			),
			array(
				array(
					'path'   => 'database/posts.sql',
					'size'   => strlen( $content ),
					'sha256' => hash( 'sha256', $content ),
					'type'   => 'regular',
				),
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $stream, $content );
		rewind( $stream );

		$writer = new PackageArchiveWriter();
		$result = $writer->write(
			$manifest,
			array( 'database/posts.sql' => $stream ),
			$destination_zip,
			$staging_dir
		);

		if ( ! $result->is_success() ) {
			$this->fail( 'PackageArchiveWriter write failed: ' . wp_json_encode( $result->get_errors() ) );
		}
		$this->assertTrue( $result->is_success() );
		$this->assertFileExists( $destination_zip );
		$this->assertSame( 1, $result->get_member_count() );

		// Clean up files.
		if ( file_exists( $destination_zip ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $destination_zip );
		}
		if ( is_dir( $staging_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $staging_dir );
		}
		if ( is_dir( $temp_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $temp_dir );
		}

		// Assert zero database modifications.
		$final_options_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$final_posts_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$this->assertSame( $initial_options_count, $final_options_count, 'No options may be written during archive write.' );
		$this->assertSame( $initial_posts_count, $final_posts_count, 'No posts may be created during archive write.' );
	}

	/**
	 * Test that inspecting a synthetic package in the WordPress runtime leaves zero database traces.
	 */
	public function test_package_archive_inspector_has_zero_persistence() {
		global $wpdb;

		$initial_options_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$initial_posts_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$temp_dir = sys_get_temp_dir() . '/sc_wp_insp_' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $temp_dir, 0700, true );

		$zip_path = $temp_dir . '/test.zip';
		$content  = 'Synthetic SQL database dump for inspector integration test.';
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'network_id'   => null,
				'base_prefix'  => $wpdb->base_prefix,
			),
			array(
				array(
					'path'   => 'database/posts.sql',
					'size'   => strlen( $content ),
					'sha256' => hash( 'sha256', $content ),
					'type'   => 'regular',
				),
			)
		);

		$zip = new \ZipArchive();
		$zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		$manifest_raw = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$zip->addFromString( 'database/posts.sql', $content );
		$zip->setMtimeName( 'database/posts.sql', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'database/posts.sql', \ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'database/posts.sql', \ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', \ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', \ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertTrue( $result->is_success(), 'Inspection failed: ' . $result->get_error_message() );
		$this->assertSame( 1, $result->get_member_count() );

		// Clean up files.
		if ( file_exists( $zip_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $zip_path );
		}
		if ( is_dir( $temp_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $temp_dir );
		}

		// Assert zero database modifications.
		$final_options_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$final_posts_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );

		$this->assertSame( $initial_options_count, $final_options_count, 'No options may be written during archive inspection.' );
		$this->assertSame( $initial_posts_count, $final_posts_count, 'No posts may be created during archive inspection.' );
	}
}
