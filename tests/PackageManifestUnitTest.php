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
			'network_id'   => null,
			'base_prefix'  => 'wp_',
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
				'network_id'   => 1,
				'base_prefix'  => 'wp_',
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
				'network_id'   => null,
				'base_prefix'  => 'wp_',
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

	/**
	 * Test that source topology rejects unexpected keys, credentials, and mode contradictions.
	 */
	public function test_manifest_rejects_source_topology_violations_and_secrets() {
		$valid_entries = array(
			array(
				'path'   => 'database/schema.sql',
				'size'   => 10,
				'sha256' => hash( 'sha256', '1234567890' ),
			),
		);

		// 1. Secret keys rejected.
		$secret_source = array(
			'is_multisite' => false,
			'site_id'      => 1,
			'network_id'   => null,
			'base_prefix'  => 'wp_',
			'db_password'  => 'secret_pass_123',
		);
		try {
			new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $secret_source, $valid_entries );
			$this->fail( 'Expected InvalidArgumentException for unexpected secret key in source.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Unexpected key in source topology', $e->getMessage() );
		}

		// 2. Mode contradictions: subsite_to_standalone with is_multisite = false.
		$invalid_mode_source1 = array(
			'is_multisite' => false,
			'network_id'   => null,
			'site_id'      => 2,
			'base_prefix'  => 'wp_',
		);
		try {
			new PackageManifest( MigrationMode::SUBSITE_TO_STANDALONE, $invalid_mode_source1, $valid_entries );
			$this->fail( 'Expected InvalidArgumentException for subsite mode with is_multisite=false.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'is_multisite', $e->getMessage() );
		}

		// 3. Mode contradictions: full_network_to_new_env with site_id set.
		$invalid_mode_source2 = array(
			'is_multisite' => true,
			'network_id'   => 1,
			'site_id'      => 2,
			'site_count'   => 5,
			'base_prefix'  => 'wp_',
		);
		try {
			new PackageManifest( MigrationMode::FULL_NETWORK_TO_NEW_ENV, $invalid_mode_source2, $valid_entries );
			$this->fail( 'Expected InvalidArgumentException for full_network with non-null site_id.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'site_id', $e->getMessage() );
		}

		// 4. Mode contradictions: standalone_to_subsite with is_multisite = true.
		$invalid_mode_source3 = array(
			'is_multisite' => true,
			'network_id'   => 1,
			'site_id'      => 1,
			'base_prefix'  => 'wp_',
		);
		try {
			new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $invalid_mode_source3, $valid_entries );
			$this->fail( 'Expected InvalidArgumentException for standalone_to_subsite with is_multisite=true.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'is_multisite', $e->getMessage() );
		}
	}

	/**
	 * Test from_array() validates raw parsed manifest input including format_version and top-level keys.
	 */
	public function test_from_array_validates_untrusted_input() {
		$valid_raw = array(
			'format_version' => '1.0',
			'mode'           => MigrationMode::STANDALONE_TO_SUBSITE,
			'source'         => array(
				'is_multisite' => false,
				'site_id'      => 1,
				'network_id'   => null,
				'base_prefix'  => 'wp_',
			),
			'entries'        => array(
				array(
					'path'   => 'database/schema.sql',
					'size'   => 10,
					'sha256' => hash( 'sha256', '1234567890' ),
				),
			),
		);

		// Valid instantiation.
		$manifest = PackageManifest::from_array( $valid_raw );
		$this->assertSame( '1.0', $manifest->get_format_version() );

		// Unsupported format version fails closed.
		$unsupported_version                   = $valid_raw;
		$unsupported_version['format_version'] = '2.0';
		try {
			PackageManifest::from_array( $unsupported_version );
			$this->fail( 'Expected InvalidArgumentException for unsupported format_version.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Unsupported format_version in manifest', $e->getMessage() );
		}

		// Extraneous top-level key rejected.
		$extra_top                = $valid_raw;
		$extra_top['extra_field'] = 'attack';
		try {
			PackageManifest::from_array( $extra_top );
			$this->fail( 'Expected InvalidArgumentException for extra top-level key.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Unexpected top-level property in manifest', $e->getMessage() );
		}
	}

	/**
	 * Test path and entry validation for unsafe aliases, non-regular entry metadata, and ADS.
	 */
	public function test_path_and_entry_validation_hardening() {
		// 1. Root-only directory name without second segment must fail.
		$res1 = PackageManifestValidator::validate_path( 'database' );
		$this->assertFalse( $res1['valid'] );

		// 2. Windows trailing dot/space segments must fail.
		$res2 = PackageManifestValidator::validate_path( 'uploads/foo. ' );
		$this->assertFalse( $res2['valid'] );

		$res3 = PackageManifestValidator::validate_path( 'uploads/CON.txt.' );
		$this->assertFalse( $res3['valid'] );

		// 3. Alternate Data Stream colon syntax must fail.
		$res4 = PackageManifestValidator::validate_path( 'uploads/file.txt:stream' );
		$this->assertFalse( $res4['valid'] );

		// 4. Non-regular entry type (symlink, hardlink, directory) must fail.
		$entry_symlink = array(
			'path'   => 'database/schema.sql',
			'size'   => 10,
			'sha256' => hash( 'sha256', '1234567890' ),
			'type'   => 'symlink',
		);
		$res5          = PackageManifestValidator::validate_entry( $entry_symlink );
		$this->assertFalse( $res5['valid'] );

		// 5. Unknown extraneous entry property must fail.
		$entry_extra = array(
			'path'        => 'database/schema.sql',
			'size'        => 10,
			'sha256'      => hash( 'sha256', '1234567890' ),
			'permissions' => 0777,
		);
		$res6        = PackageManifestValidator::validate_entry( $entry_extra );
		$this->assertFalse( $res6['valid'] );
	}

	/**
	 * Test stream verifier sanitizes paths, fails on non-local wrappers, handles non-EOF empty reads, and rejects duplicate handles.
	 */
	public function test_stream_verifier_security_hardening() {
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
					'size'   => 5,
					'sha256' => hash( 'sha256', 'hello' ),
				),
				array(
					'path'   => 'assets/empty.txt',
					'size'   => 0,
					'sha256' => hash( 'sha256', '' ),
				),
			)
		);

		$verifier = new PackageStreamVerifier();

		// 1. Secret/sensitive path in unexpected stream is sanitized.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s1 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s2 = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_secret    = fopen( 'php://temp', 'r+' );
		$hostile_key = '/etc/shadow/super_secret_user_password';

		$res = $verifier->verify(
			$manifest,
			array(
				'database/schema.sql' => $s1,
				'assets/empty.txt'    => $s2,
				$hostile_key          => $s_secret,
			)
		);
		$this->assertFalse( $res->is_success() );
		$json_out = wp_json_encode( $res );
		$this->assertStringNotContainsString( 'super_secret_user_password', (string) $json_out );
		$this->assertStringNotContainsString( '/etc/shadow', (string) $json_out );

		// 2. Duplicate stream handle supplied to multiple entries is rejected.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_shared = fopen( 'php://temp', 'r+' );
		$res_dup  = $verifier->verify(
			$manifest,
			array(
				'database/schema.sql' => $s_shared,
				'assets/empty.txt'    => $s_shared,
			)
		);
		$this->assertFalse( $res_dup->is_success() );
		$this->assertSame( 'DUPLICATE_STREAM_RESOURCE', $res_dup->get_errors()[0]['code'] );

		// 3. Reject nonlocal stream wrappers: data:// wrapper.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_data = fopen( 'data://text/plain;base64,aGVsbG8=', 'r' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_temp   = fopen( 'php://temp', 'r+' );
		$res_data = $verifier->verify(
			$manifest,
			array(
				'database/schema.sql' => $s_data,
				'assets/empty.txt'    => $s_temp,
			)
		);
		$this->assertFalse( $res_data->is_success() );
		$this->assertSame( 'DISALLOWED_STREAM_WRAPPER', $res_data->get_errors()[0]['code'] );

		// 4. Reject php://filter wrappers even if reported under PHP wrapper.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_filter = fopen( 'php://filter/read=string.rot13/resource=php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_temp2    = fopen( 'php://temp', 'r+' );
		$res_filter = $verifier->verify(
			$manifest,
			array(
				'database/schema.sql' => $s_filter,
				'assets/empty.txt'    => $s_temp2,
			)
		);
		$this->assertFalse( $res_filter->is_success() );
		$this->assertSame( 'DISALLOWED_STREAM_WRAPPER', $res_filter->get_errors()[0]['code'] );

		// 5. Reject write-only stream handles.
		$tmp_file = tempnam( sys_get_temp_dir(), 'sc_test_' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_writeonly = fopen( $tmp_file, 'w' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$s_temp3   = fopen( 'php://temp', 'r+' );
		$res_write = $verifier->verify(
			$manifest,
			array(
				'database/schema.sql' => $s_writeonly,
				'assets/empty.txt'    => $s_temp3,
			)
		);
		if ( file_exists( $tmp_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp_file );
		}
		$this->assertFalse( $res_write->is_success() );
		$this->assertSame( 'UNREADABLE_STREAM', $res_write->get_errors()[0]['code'] );
	}

	/**
	 * Test UTF-8 and cross-platform Unicode filename validation and casefold collision rejection.
	 */
	public function test_utf8_and_unicode_filename_validation() {
		// 1. Valid UTF-8 filenames with international characters are accepted.
		$res_valid_unicode = PackageManifestValidator::validate_path( 'uploads/2026/01/ünicode-ö.jpg' );
		$this->assertTrue( $res_valid_unicode['valid'], 'Valid UTF-8 path should be accepted.' );

		// 2. Malformed / invalid UTF-8 byte sequences are rejected.
		$invalid_utf8     = "uploads/bad-\xC3\x28-sequence.jpg";
		$res_invalid_utf8 = PackageManifestValidator::validate_path( $invalid_utf8 );
		$this->assertFalse( $res_invalid_utf8['valid'], 'Invalid UTF-8 byte sequence must be rejected.' );

		// 3. Decomposed Unicode characters (combining marks / NFD) are rejected.
		// "e" followed by combining acute accent U+0301 (decomposed café).
		$decomposed_path = "uploads/cafe\xCC\x81.png";
		$res_decomposed  = PackageManifestValidator::validate_path( $decomposed_path );
		$this->assertFalse( $res_decomposed['valid'], 'Decomposed Unicode combining mark sequences must be rejected.' );

		// Composed form (U+00E9) is accepted.
		$composed_path = "uploads/caf\xC3\xA9.png";
		$res_composed  = PackageManifestValidator::validate_path( $composed_path );
		$this->assertTrue( $res_composed['valid'], 'Precomposed Unicode characters must be accepted.' );

		// 4. Unicode case-fold collision rejection in PackageManifest.
		$source                    = array(
			'is_multisite' => false,
			'site_id'      => 1,
			'network_id'   => null,
			'base_prefix'  => 'wp_',
		);
		$unicode_collision_entries = array(
			array(
				'path'   => 'uploads/Ü.png',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'first' ),
			),
			array(
				'path'   => 'uploads/ü.png',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'second' ),
			),
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Case-collision duplicate entry path detected.' );
		new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $source, $unicode_collision_entries );
	}

	/**
	 * Test exception and error sanitization to prevent sensitive input leakage.
	 */
	public function test_exceptions_and_errors_do_not_leak_untrusted_input() {
		$secret_sentinel = 'super_secret_customer_token_999';

		// 1. Malicious top-level property in from_array().
		$raw_with_secret_prop = array(
			'format_version' => '1.0',
			'mode'           => MigrationMode::STANDALONE_TO_SUBSITE,
			'source'         => array(
				'is_multisite' => false,
				'site_id'      => 1,
			),
			'entries'        => array(),
			$secret_sentinel => 'hostile_value',
		);
		try {
			PackageManifest::from_array( $raw_with_secret_prop );
			$this->fail( 'Expected exception for extra top-level property.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( $secret_sentinel, $e->getMessage() );
		}

		// 2. Malicious format_version in from_array().
		$raw_with_secret_version = array(
			'format_version' => '2.0_' . $secret_sentinel,
			'mode'           => MigrationMode::STANDALONE_TO_SUBSITE,
			'source'         => array(
				'is_multisite' => false,
				'site_id'      => 1,
			),
			'entries'        => array(),
		);
		try {
			PackageManifest::from_array( $raw_with_secret_version );
			$this->fail( 'Expected exception for unsupported format_version.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( $secret_sentinel, $e->getMessage() );
		}

		// 3. Extraneous entry key containing secret in validate_entry().
		$entry_with_secret_key = array(
			'path'           => 'database/schema.sql',
			'size'           => 10,
			'sha256'         => hash( 'sha256', 'test' ),
			$secret_sentinel => 'leaked_data',
		);
		$res_entry_key         = PackageManifestValidator::validate_entry( $entry_with_secret_key );
		$this->assertFalse( $res_entry_key['valid'] );
		$this->assertStringNotContainsString( $secret_sentinel, $res_entry_key['error'] );

		// 4. Non-regular entry type containing secret in validate_entry().
		$entry_with_secret_type = array(
			'path'   => 'database/schema.sql',
			'size'   => 10,
			'sha256' => hash( 'sha256', 'test' ),
			'type'   => 'symlink_' . $secret_sentinel,
		);
		$res_entry_type         = PackageManifestValidator::validate_entry( $entry_with_secret_type );
		$this->assertFalse( $res_entry_type['valid'] );
		$this->assertStringNotContainsString( $secret_sentinel, $res_entry_type['error'] );

		// 5. Unapproved root prefix containing secret in validate_path().
		$res_root = PackageManifestValidator::validate_path( 'secret_root_' . $secret_sentinel . '/dump.sql' );
		$this->assertFalse( $res_root['valid'] );
		$this->assertStringNotContainsString( $secret_sentinel, $res_root['error'] );

		// 6. Duplicate path containing secret in PackageManifest constructor.
		$source                   = array(
			'is_multisite' => false,
			'site_id'      => 1,
			'base_prefix'  => 'wp_',
		);
		$secret_path              = 'database/dump_' . $secret_sentinel . '.sql';
		$duplicate_secret_entries = array(
			array(
				'path'   => $secret_path,
				'size'   => 5,
				'sha256' => hash( 'sha256', 'a' ),
			),
			array(
				'path'   => $secret_path,
				'size'   => 5,
				'sha256' => hash( 'sha256', 'b' ),
			),
		);
		try {
			new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $source, $duplicate_secret_entries );
			$this->fail( 'Expected exception for duplicate entry path.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( $secret_sentinel, $e->getMessage() );
		}
	}

	/**
	 * Test base_prefix validation and error message sanitization.
	 */
	public function test_base_prefix_validation_and_error_sanitization() {
		$valid_entries = array(
			array(
				'path'   => 'database/schema.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
			),
		);

		// 1. Hostile base_prefix with secret or path traversal is rejected.
		$hostile_prefix_source = array(
			'is_multisite' => false,
			'site_id'      => 1,
			'network_id'   => null,
			'base_prefix'  => '/etc/passwd/secret_wp_',
		);
		try {
			new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $hostile_prefix_source, $valid_entries );
			$this->fail( 'Expected InvalidArgumentException for hostile base_prefix.' );
		} catch ( \InvalidArgumentException $e ) {
			// Exception message must be generic and not leak secret path.
			$this->assertStringNotContainsString( 'secret_wp_', $e->getMessage() );
			$this->assertStringNotContainsString( '/etc/passwd', $e->getMessage() );
		}

		// 2. Hostile source key containing secret password name is sanitized in exception.
		$hostile_key_source = array(
			'is_multisite'               => false,
			'site_id'                    => 1,
			'network_id'                 => null,
			'base_prefix'                => 'wp_',
			'user_password_secret_token' => 'leaked_value',
		);
		try {
			new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $hostile_key_source, $valid_entries );
			$this->fail( 'Expected InvalidArgumentException for hostile source key.' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringNotContainsString( 'user_password_secret_token', $e->getMessage() );
			$this->assertStringNotContainsString( 'leaked_value', $e->getMessage() );
		}
	}
}
