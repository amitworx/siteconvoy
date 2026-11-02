<?php
/**
 * Package Manifest and Stream Verifier Unit Tests.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use PHPUnit\Framework\TestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Package\PackageManifest;
use SiteConvoy\Package\PackageManifestValidator;
use SiteConvoy\Package\PackageStreamVerifier;

/**
 * Class PackageManifestUnitTest
 */
class PackageManifestUnitTest extends TestCase {

	/**
	 * Test valid package manifest construction and serialization for all 4 modes.
	 */
	public function test_valid_package_manifest_for_all_modes() {
		$schema_path = dirname( __DIR__ ) . '/schemas/package-manifest.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$schema = json_decode( (string) file_get_contents( $schema_path ), true );

		$modes = array(
			MigrationMode::SUBSITE_TO_STANDALONE   => array(
				'is_multisite' => true,
				'network_id'   => 1,
				'site_id'      => 2,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::SUBSITE_TO_MULTISITE    => array(
				'is_multisite' => true,
				'network_id'   => 1,
				'site_id'      => 3,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::FULL_NETWORK_TO_NEW_ENV => array(
				'is_multisite' => true,
				'network_id'   => 1,
				'site_id'      => null,
				'site_count'   => 5,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::STANDALONE_TO_SUBSITE   => array(
				'is_multisite' => false,
				'network_id'   => null,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
		);

		$sample_entries = array(
			array(
				'path'   => 'database/schema.sql',
				'size'   => 1024,
				'sha256' => hash( 'sha256', 'sample sql' ),
			),
			array(
				'path'   => 'uploads/2026/01/photo.jpg',
				'size'   => 2048,
				'sha256' => hash( 'sha256', 'sample photo' ),
			),
		);

		foreach ( $modes as $mode => $source ) {
			$manifest = new PackageManifest( $mode, $source, $sample_entries );
			$this->assertSame( '1.0', $manifest->get_format_version() );
			$this->assertSame( $mode, $manifest->get_mode() );
			$this->assertSame( $source, $manifest->get_source() );
			$this->assertCount( 2, $manifest->get_entries() );

			$json = wp_json_encode( $manifest );
			$this->assertNotFalse( $json );

			$decoded = json_decode( (string) $json );
			$this->assertIsObject( $decoded );

			$errors = JsonSchemaValidator::validate( $decoded, $schema );
			$this->assertEmpty( $errors, sprintf( "Mode %s failed schema validation:\n%s", $mode, implode( "\n", $errors ) ) );
		}
	}

	/**
	 * Test that entries are canonicalized into deterministic path order and duplicate paths are rejected.
	 */
	public function test_manifest_orders_entries_and_rejects_duplicates() {
		$source = array(
			'is_multisite' => false,
			'site_id'      => 1,
		);

		// Unordered entries.
		$unordered_entries = array(
			array(
				'path'   => 'uploads/b.txt',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
			),
			array(
				'path'   => 'database/tables.sql',
				'size'   => 10,
				'sha256' => hash( 'sha256', 'tables sql' ),
			),
			array(
				'path'   => 'uploads/a.txt',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'world' ),
			),
		);

		$manifest = new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $source, $unordered_entries );
		$entries  = $manifest->get_entries();

		// Should be deterministically sorted by path.
		$this->assertSame( 'database/tables.sql', $entries[0]['path'] );
		$this->assertSame( 'uploads/a.txt', $entries[1]['path'] );
		$this->assertSame( 'uploads/b.txt', $entries[2]['path'] );

		// Duplicate path rejection.
		$duplicate_entries = array(
			array(
				'path'   => 'database/tables.sql',
				'size'   => 10,
				'sha256' => hash( 'sha256', '1' ),
			),
			array(
				'path'   => 'database/tables.sql',
				'size'   => 20,
				'sha256' => hash( 'sha256', '2' ),
			),
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Duplicate entry path' );
		new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $source, $duplicate_entries );
	}

	/**
	 * Test path validation rejects malicious, traversal, absolute, and reserved paths.
	 *
	 * @dataProvider invalid_paths_provider
	 * @param string $path Invalid path.
	 * @param string $expected_error Substring of expected error.
	 */
	public function test_path_validation_rejects_unsafe_paths( $path, $expected_error ) {
		$result = PackageManifestValidator::validate_path( $path );
		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( $expected_error, $result['error'] );
	}

	/**
	 * Data provider for invalid entry paths.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function invalid_paths_provider() {
		return array(
			'empty path'                => array( '', 'Empty path' ),
			'absolute slash'            => array( '/database/dump.sql', 'Absolute path' ),
			'absolute backslash'        => array( '\database\dump.sql', 'Backslash or drive path' ),
			'drive letter'              => array( 'C:/wp-content/test.txt', 'Backslash or drive path' ),
			'unc path'                  => array( '//server/share/file.txt', 'Absolute path' ),
			'traversal dot dot'         => array( 'database/../../wp-config.php', 'Path traversal' ),
			'current dir segment'       => array( 'database/./dump.sql', 'Path traversal' ),
			'trailing slash'            => array( 'database/dump.sql/', 'Trailing slash' ),
			'reserved manifest.json'    => array( 'manifest.json', 'Reserved filename' ),
			'reserved checksums.sha256' => array( 'checksums.sha256', 'Reserved filename' ),
			'wp-config prohibited'      => array( 'wp-config.php', 'Prohibited path' ),
			'env prohibited'            => array( '.env', 'Prohibited path' ),
			'unapproved root'           => array( 'other_dir/file.txt', 'Unapproved root prefix' ),
			'null byte'                 => array( "database/dump.sql\0", 'Control or null character' ),
			'con device'                => array( 'uploads/CON', 'Reserved device name' ),
		);
	}

	/**
	 * Test streaming SHA-256 verifier with valid streams and bounded memory.
	 */
	public function test_stream_verifier_verifies_valid_payloads() {
		$content1 = 'Database SQL content sample.';
		$content2 = ''; // Empty file test.
		$content3 = str_repeat( 'A', 2 * 1024 * 1024 ); // 2MB stream test.

		$entries = array(
			array(
				'path'   => 'database/schema.sql',
				'size'   => strlen( $content1 ),
				'sha256' => hash( 'sha256', $content1 ),
			),
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
			),
			array(
				'path'   => 'uploads/large.bin',
				'size'   => strlen( $content3 ),
				'sha256' => hash( 'sha256', $content3 ),
			),
		);

		$manifest = new PackageManifest(
			MigrationMode::SUBSITE_TO_STANDALONE,
			array(
				'is_multisite' => true,
				'site_id'      => 2,
			),
			$entries
		);

		// Provide streams via stream resources.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream1 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $stream1, $content1 );
		rewind( $stream1 );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream2 = fopen( 'php://temp', 'r+' ); // Empty stream.

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream3 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $stream3, $content3 );
		rewind( $stream3 );

		$stream_map = array(
			'database/schema.sql' => $stream1,
			'assets/empty.txt'    => $stream2,
			'uploads/large.bin'   => $stream3,
		);

		$verifier = new PackageStreamVerifier();
		$result   = $verifier->verify( $manifest, $stream_map );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 3, $result->get_verified_count() );
		$this->assertEmpty( $result->get_errors() );

		// Streams should be closed by verifier by default.
		$this->assertFalse( is_resource( $stream1 ) );
		$this->assertFalse( is_resource( $stream2 ) );
		$this->assertFalse( is_resource( $stream3 ) );
	}

	/**
	 * Test stream verifier fails closed on hash corruption, truncation, overlong streams, and missing streams.
	 */
	public function test_stream_verifier_fails_closed_on_corrupted_payloads() {
		$content = 'Correct expected content.';
		$entries = array(
			array(
				'path'   => 'database/schema.sql',
				'size'   => strlen( $content ),
				'sha256' => hash( 'sha256', $content ),
			),
		);

		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
			),
			$entries
		);

		$verifier = new PackageStreamVerifier();

		// Case 1: Corrupted content (exact same byte length, wrong SHA256).
		$corrupted_content = substr_replace( $content, 'X', 0, 1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s1 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $s1, $corrupted_content );
		rewind( $s1 );
		$res1 = $verifier->verify( $manifest, array( 'database/schema.sql' => $s1 ) );
		$this->assertFalse( $res1->is_success() );
		$this->assertSame( 'CHECKSUM_MISMATCH', $res1->get_errors()[0]['code'] );

		// Case 2: Truncated stream (fewer bytes than declared size).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s2 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $s2, 'Short' );
		rewind( $s2 );
		$res2 = $verifier->verify( $manifest, array( 'database/schema.sql' => $s2 ) );
		$this->assertFalse( $res2->is_success() );
		$this->assertSame( 'SIZE_MISMATCH', $res2->get_errors()[0]['code'] );

		// Case 3: Overlong stream (more bytes than declared size).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s3 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $s3, $content . 'extra bytes' );
		rewind( $s3 );
		$res3 = $verifier->verify( $manifest, array( 'database/schema.sql' => $s3 ) );
		$this->assertFalse( $res3->is_success() );
		$this->assertSame( 'SIZE_MISMATCH', $res3->get_errors()[0]['code'] );

		// Case 4: Missing stream in map.
		$res4 = $verifier->verify( $manifest, array() );
		$this->assertFalse( $res4->is_success() );
		$this->assertSame( 'STREAM_MISSING', $res4->get_errors()[0]['code'] );

		// Case 5: Unexpected extra stream in map.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s5a = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $s5a, $content );
		rewind( $s5a );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s5b  = fopen( 'php://temp', 'r+' );
		$res5 = $verifier->verify(
			$manifest,
			array(
				'database/schema.sql' => $s5a,
				'extra/file.txt'      => $s5b,
			)
		);
		$this->assertFalse( $res5->is_success() );
		$this->assertSame( 'UNEXPECTED_STREAM', $res5->get_errors()[0]['code'] );
	}
}
