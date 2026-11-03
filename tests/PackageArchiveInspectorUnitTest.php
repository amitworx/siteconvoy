<?php
/**
 * Package Archive Inspector Unit Tests.
 *
 * Verifies read-only package archive inspection, structure validation, deterministic
 * metadata checks, manifest schema and raw byte equivalence, bounded-memory payload verification,
 * and fail-closed handling of malformed or malicious archives.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

require_once dirname( __DIR__ ) . '/siteconvoy.php';

use PHPUnit\Framework\TestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Package\PackageManifest;
use SiteConvoy\Package\PackageArchiveInspector;
use SiteConvoy\Package\PackageArchiveInspectionResult;
use ZipArchive;

/**
 * Class PackageArchiveInspectorUnitTest
 */
class PackageArchiveInspectorUnitTest extends TestCase {

	/**
	 * Temporary directory for synthetic test archives.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc_inspector_test_' . uniqid();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $this->temp_dir, 0700, true );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		$this->recursive_remove_dir( $this->temp_dir );
		parent::tearDown();
	}

	/**
	 * Recursively remove directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->recursive_remove_dir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $dir );
	}

	/**
	 * Helper to create a synthetic valid SiteConvoy v1 ZIP archive directly.
	 *
	 * Uses ZipArchive with CM_STORE, deterministic mtime (1767225600), and
	 * deterministic regular file attributes (0100644 << 16).
	 *
	 * @param string                $path     Destination ZIP path.
	 * @param PackageManifest|null  $manifest Manifest object (or null for default).
	 * @param array<string, string> $payloads Map of relative path => raw content.
	 * @param array<string, mixed>  $options  Overrides for corruption/adversarial testing.
	 * @return string Archive path.
	 */
	private function create_synthetic_archive( $path, PackageManifest $manifest = null, array $payloads = array(), array $options = array() ) {
		if ( null === $manifest ) {
			$entries = array();
			foreach ( $payloads as $p => $c ) {
				$entries[] = array(
					'path'   => $p,
					'size'   => strlen( $c ),
					'sha256' => hash( 'sha256', $c ),
					'type'   => 'regular',
				);
			}
			$manifest = new PackageManifest(
				MigrationMode::STANDALONE_TO_SUBSITE,
				array(
					'is_multisite' => false,
					'site_id'      => 1,
					'base_prefix'  => 'wp_',
				),
				$entries
			);
		}

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		$manifest_raw = isset( $options['custom_manifest_raw'] )
			? (string) $options['custom_manifest_raw']
			: wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$mtime     = $options['mtime'] ?? PackageArchiveInspector::DETERMINISTIC_MTIME;
		$comp      = $options['comp_method'] ?? ZipArchive::CM_STORE;
		$unix_attr = $options['external_attr'] ?? PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR;

		// Collect all member names in canonical sort order: manifest.json + sorted payload entries.
		$all_members = array();
		if ( empty( $options['skip_manifest'] ) ) {
			$all_members['manifest.json'] = $manifest_raw;
		}
		foreach ( $payloads as $p => $c ) {
			$all_members[ $p ] = $c;
		}
		if ( ! empty( $options['extra_files'] ) && is_array( $options['extra_files'] ) ) {
			foreach ( $options['extra_files'] as $extra_name => $extra_content ) {
				$all_members[ $extra_name ] = $extra_content;
			}
		}

		$all_names = array_keys( $all_members );
		if ( empty( $options['skip_sort'] ) ) {
			sort( $all_names, SORT_STRING );
		}

		foreach ( $all_names as $name ) {
			$zip->addFromString( $name, $all_members[ $name ] );
			$zip->setMtimeName( $name, $mtime );
			$zip->setCompressionName( $name, $comp );
			$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $unix_attr );
		}

		$zip->close();
		return $path;
	}

	/**
	 * Test that a valid synthetic archive is inspected successfully.
	 */
	public function test_inspect_valid_synthetic_package_succeeds() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'valid.zip';
		$payloads = array(
			'database/schema.sql' => 'CREATE TABLE test (id INT);',
			'uploads/image.png'   => 'BINARY_PNG_DATA_HERE',
		);

		$this->create_synthetic_archive( $zip_path, null, $payloads );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertInstanceOf( PackageArchiveInspectionResult::class, $result );
		$this->assertTrue( $result->is_success(), 'Expected success but got: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		$this->assertSame( '', $result->get_error_code() );
		$this->assertSame( '', $result->get_error_message() );
		$this->assertSame( 2, $result->get_member_count() );
		$this->assertGreaterThan( 0, $result->get_archive_size() );
		$this->assertSame( hash_file( 'sha256', $zip_path ), $result->get_archive_sha256() );

		$manifest = $result->get_manifest();
		$this->assertInstanceOf( PackageManifest::class, $manifest );
		$this->assertSame( MigrationMode::STANDALONE_TO_SUBSITE, $manifest->get_mode() );
		$this->assertCount( 2, $manifest->get_entries() );
	}

	/**
	 * Test that inspection fails if zip extension capability is unavailable.
	 */
	public function test_inspect_fails_when_zip_capability_unavailable() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'dummy.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $zip_path, 'PKfake' );

		$inspector = new class() extends PackageArchiveInspector {
			/**
			 * Stub zip extension availability.
			 *
			 * @return bool False.
			 */
			protected function is_zip_available() {
				return false;
			}
		};

		$result = $inspector->inspect( $zip_path );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_CAPABILITY_UNAVAILABLE', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails for nonexistent archive path.
	 */
	public function test_inspect_fails_when_archive_not_found() {
		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $this->temp_dir . DIRECTORY_SEPARATOR . 'missing.zip' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ARCHIVE_NOT_FOUND', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails for stream wrappers and URL schemes.
	 */
	public function test_inspect_fails_for_stream_wrapper_path() {
		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( 'php://filter/read=convert.base64-encode/resource=dummy.zip' );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_ARCHIVE_PATH', $result->get_error_code() );

		$result = $inspector->inspect( 'phar:///tmp/test.phar/archive.zip' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_ARCHIVE_PATH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails when target is a directory rather than regular file.
	 */
	public function test_inspect_fails_when_archive_is_directory() {
		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $this->temp_dir );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_ARCHIVE_TYPE', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails when target file is not a valid ZIP container.
	 */
	public function test_inspect_fails_for_corrupt_non_zip_file() {
		$corrupt_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'corrupt.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $corrupt_path, 'THIS_IS_NOT_A_ZIP_ARCHIVE' );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $corrupt_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_OPEN_FAILED', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if manifest.json is missing from the archive.
	 */
	public function test_inspect_fails_when_manifest_is_missing() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'no_manifest.zip';
		$this->create_synthetic_archive( $zip_path, null, array( 'uploads/pic.jpg' => 'data' ), array( 'skip_manifest' => true ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_NOT_FOUND', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if manifest.json exceeds maximum size limit.
	 */
	public function test_inspect_fails_when_manifest_exceeds_size_limit() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'huge_manifest.zip';
		$big_json = '{"format_version":"1.0","padding":"' . str_repeat( 'A', 2 * 1024 * 1024 + 50 ) . '"}';

		$this->create_synthetic_archive( $zip_path, null, array(), array( 'custom_manifest_raw' => $big_json ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_EXCEEDS_SIZE_LIMIT', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails when manifest contains invalid JSON.
	 */
	public function test_inspect_fails_for_malformed_json_manifest() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_json.zip';
		$this->create_synthetic_archive( $zip_path, null, array(), array( 'custom_manifest_raw' => '{invalid_json' ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_INVALID_JSON', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if non-ASCII path is present when Unicode extensions are absent.
	 */
	public function test_inspect_fails_for_unicode_path_without_runtime_capabilities() {
		$zip_path     = $this->temp_dir . DIRECTORY_SEPARATOR . 'unicode_path.zip';
		$unicode_path = 'uploads/café.png';

		// Construct raw archive directly without PackageManifest constructor throwing.
		$raw_manifest = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":[{"path":"' . $unicode_path . '","size":4,"sha256":"' . hash( 'sha256', 'data' ) . '","type":"regular"}]}';

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'manifest.json', $raw_manifest );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( $unicode_path, 'data' );
		$zip->setMtimeName( $unicode_path, PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( $unicode_path, ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( $unicode_path, ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );
		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		// On environment with no intl extension, this must fail closed.
		if ( ! extension_loaded( 'intl' ) || ! extension_loaded( 'mbstring' ) ) {
			$this->assertFalse( $result->is_success() );
			$this->assertSame( 'ZIP_INVALID_ENTRY_PATH', $result->get_error_code() );
		} else {
			$this->assertTrue( $result->is_success() );
		}
	}

	/**
	 * Test that inspect fails if manifest contains duplicate keys.
	 */
	public function test_inspect_fails_when_manifest_contains_duplicate_keys() {
		$zip_path     = $this->temp_dir . DIRECTORY_SEPARATOR . 'dup_keys.zip';
		$dup_key_json = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":[],"mode":"full_network_to_new_env"}';
		$this->create_synthetic_archive( $zip_path, null, array(), array( 'custom_manifest_raw' => $dup_key_json ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_DUPLICATE_KEY', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if manifest entries is an object instead of array.
	 */
	public function test_inspect_fails_when_entries_is_object_rather_than_array() {
		$zip_path    = $this->temp_dir . DIRECTORY_SEPARATOR . 'entries_obj.zip';
		$object_json = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":{"0":{"path":"uploads/a.png","size":0,"sha256":"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","type":"regular"}}}';
		$this->create_synthetic_archive( $zip_path, null, array(), array( 'custom_manifest_raw' => $object_json ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_SCHEMA_INVALID', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if manifest entries are out of canonical sort order.
	 */
	public function test_inspect_fails_when_manifest_entries_are_unsorted() {
		$zip_path      = $this->temp_dir . DIRECTORY_SEPARATOR . 'unsorted_entries.zip';
		$unsorted_json = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":[{"path":"uploads/z.png","size":1,"sha256":"' . hash( 'sha256', 'z' ) . '","type":"regular"},{"path":"uploads/a.png","size":1,"sha256":"' . hash( 'sha256', 'a' ) . '","type":"regular"}]}';

		$payloads = array(
			'uploads/z.png' => 'z',
			'uploads/a.png' => 'a',
		);
		$this->create_synthetic_archive( $zip_path, null, $payloads, array( 'custom_manifest_raw' => $unsorted_json ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_NONCANONICAL_SERIALIZATION', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if raw manifest bytes do not match canonical serialization.
	 */
	public function test_inspect_fails_when_manifest_has_noncanonical_whitespace_or_formatting() {
		$zip_path    = $this->temp_dir . DIRECTORY_SEPARATOR . 'whitespace_manifest.zip';
		$pretty_json = "{\n  \"format_version\": \"1.0\",\n  \"mode\": \"standalone_to_subsite\",\n  \"source\": {\n    \"is_multisite\": false,\n    \"site_id\": 1,\n    \"base_prefix\": \"wp_\"\n  },\n  \"entries\": []\n}";
		$this->create_synthetic_archive( $zip_path, null, array(), array( 'custom_manifest_raw' => $pretty_json ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'MANIFEST_NONCANONICAL_SERIALIZATION', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if archive contains duplicate ZIP entry names.
	 */
	public function test_inspect_fails_when_archive_has_duplicate_member_names() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'dup_members.zip';

		// Construct raw ZIP with 2 central directory entries sharing the identical path.
		$make_local = function ( $name, $data ) {
			$len      = strlen( $data );
			$crc      = crc32( $data );
			$name_len = strlen( $name );
			return pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, $name_len, 0 ) . $name . $data;
		};

		$make_cd = function ( $name, $data, $offset ) {
			$len      = strlen( $data );
			$crc      = crc32( $data );
			$name_len = strlen( $name );
			$ext_attr = PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR;
			return pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 0x031e, 20, 0, 0, 0, 0, $crc, $len, $len, $name_len, 0, 0, 0, 0, $ext_attr, $offset ) . $name;
		};

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$f  = fopen( $zip_path, 'wb' );
		$h1 = $make_local( 'manifest.json', '{}' );
		$o1 = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $f, $h1 );

		$h2 = $make_local( 'uploads/a.png', 'AAA' );
		$o2 = strlen( $h1 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $f, $h2 );

		$h3 = $make_local( 'uploads/a.png', 'BBB' );
		$o3 = $o2 + strlen( $h2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $f, $h3 );

		$cd_start = ftell( $f );
		$cd1      = $make_cd( 'manifest.json', '{}', $o1 );
		$cd2      = $make_cd( 'uploads/a.png', 'AAA', $o2 );
		$cd3      = $make_cd( 'uploads/a.png', 'BBB', $o3 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $f, $cd1 . $cd2 . $cd3 );
		$cd_size = ftell( $f ) - $cd_start;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $f, pack( 'VvvvvVVv', 0x06054b50, 0, 0, 3, 3, $cd_size, $cd_start, 0 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $f );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_DUPLICATE_MEMBER', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if archive contains unmanifested extra members.
	 */
	public function test_inspect_fails_when_archive_has_unmanifested_member() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'extra_member.zip';
		$payloads = array( 'uploads/pic.png' => 'data' );

		$this->create_synthetic_archive(
			$zip_path,
			null,
			$payloads,
			array(
				'extra_files' => array( 'uploads/secret.txt' => 'extra' ),
			)
		);

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_COUNT_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if archive is missing a declared payload member.
	 */
	public function test_inspect_fails_when_archive_missing_declared_member() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'missing_member.zip';
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			array(
				array(
					'path'   => 'uploads/declared_but_missing.png',
					'size'   => 4,
					'sha256' => hash( 'sha256', 'data' ),
					'type'   => 'regular',
				),
			)
		);

		// Create archive without the payload file.
		$this->create_synthetic_archive( $zip_path, $manifest, array() );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_COUNT_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if members do not follow canonical sort order.
	 */
	public function test_inspect_fails_when_member_order_is_misordered() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'misordered.zip';
		$payloads = array(
			'uploads/z.png' => 'z',
			'uploads/a.png' => 'a',
		);

		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			array(
				array(
					'path'   => 'uploads/a.png',
					'size'   => 1,
					'sha256' => hash( 'sha256', 'a' ),
					'type'   => 'regular',
				),
				array(
					'path'   => 'uploads/z.png',
					'size'   => 1,
					'sha256' => hash( 'sha256', 'z' ),
					'type'   => 'regular',
				),
			)
		);

		// Add members in wrong order: z before a.
		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$manifest_raw = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'uploads/z.png', 'z' );
		$zip->setMtimeName( 'uploads/z.png', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'uploads/z.png', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'uploads/z.png', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'uploads/a.png', 'a' );
		$zip->setMtimeName( 'uploads/a.png', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'uploads/a.png', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'uploads/a.png', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_ORDER_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if a member has non-deterministic mtime.
	 */
	public function test_inspect_fails_when_member_mtime_tampered() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_mtime.zip';
		$this->create_synthetic_archive( $zip_path, null, array( 'uploads/file.txt' => 'data' ), array( 'mtime' => 123456789 ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_METADATA_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if a member is compressed instead of stored.
	 */
	public function test_inspect_fails_when_member_compression_not_store() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'compressed.zip';
		$this->create_synthetic_archive( $zip_path, null, array( 'uploads/file.txt' => 'data' ), array( 'comp_method' => ZipArchive::CM_DEFLATE ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_METADATA_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if member external attributes represent a symbolic link.
	 */
	public function test_inspect_fails_when_member_is_symlink() {
		$zip_path     = $this->temp_dir . DIRECTORY_SEPARATOR . 'symlink_member.zip';
		$symlink_attr = 0120777 << 16;
		$this->create_synthetic_archive( $zip_path, null, array( 'uploads/link' => 'target' ), array( 'external_attr' => $symlink_attr ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_SYMLINK_MEMBER_PROHIBITED', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if member name represents a directory.
	 */
	public function test_inspect_fails_when_archive_contains_directory_member() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'dir_member.zip';
		$this->create_synthetic_archive(
			$zip_path,
			null,
			array(),
			array(
				'extra_files' => array( 'uploads/' => '' ),
			)
		);

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_DIRECTORY_MEMBER_PROHIBITED', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if payload member size does not match manifest.
	 */
	public function test_inspect_fails_when_payload_size_mismatched() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'size_mismatch.zip';
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			array(
				array(
					'path'   => 'uploads/file.txt',
					'size'   => 100, // Declared 100 but actual 4 bytes.
					'sha256' => hash( 'sha256', 'data' ),
					'type'   => 'regular',
				),
			)
		);
		$this->create_synthetic_archive( $zip_path, $manifest, array( 'uploads/file.txt' => 'data' ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_SIZE_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if payload checksum does not match manifest.
	 */
	public function test_inspect_fails_when_payload_checksum_mismatched() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'hash_mismatch.zip';
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			array(
				array(
					'path'   => 'uploads/file.txt',
					'size'   => 4,
					'sha256' => str_repeat( '0', 64 ), // Corrupted hash digest.
					'type'   => 'regular',
				),
			)
		);
		$this->create_synthetic_archive( $zip_path, $manifest, array( 'uploads/file.txt' => 'data' ) );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_CHECKSUM_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that an independent adversarial fixture crafted outside SiteConvoy writer fails closed.
	 */
	public function test_inspect_rejects_adversarial_crafted_fixture() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'malicious_traversal.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		// Injected path traversal in ZIP entry name.
		$zip->addFromString( '../traversal.txt', 'ATTACK_PAYLOAD' );
		$zip->setMtimeName( '../traversal.txt', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( '../traversal.txt', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( '../traversal.txt', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );
		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		// Must fail closed with structured error (invalid path or missing manifest).
		$this->assertNotEmpty( $result->get_error_code() );
	}

	/**
	 * Test that inspect operates read-only and does not mutate archive file or directory.
	 */
	public function test_inspect_does_not_mutate_archive_or_filesystem() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'readonly_test.zip';
		$payloads = array( 'uploads/pic.png' => 'picture_content' );
		$this->create_synthetic_archive( $zip_path, null, $payloads );

		$initial_hash = hash_file( 'sha256', $zip_path );
		$initial_size = filesize( $zip_path );
		$initial_time = filemtime( $zip_path );

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertTrue( $result->is_success(), 'Expected success: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		$this->assertSame( $initial_hash, hash_file( 'sha256', $zip_path ) );
		$this->assertSame( $initial_size, filesize( $zip_path ) );
		$this->assertSame( $initial_time, filemtime( $zip_path ) );
	}

	/**
	 * Test bounded memory streaming on large payload member.
	 */
	public function test_inspect_bounded_memory_on_multi_megabyte_payload() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'big_payload.zip';
		// 4 MB synthetic stream payload.
		$payload_size = 4 * 1024 * 1024;
		$chunk        = str_repeat( 'X', 64 * 1024 );
		$chunks_count = $payload_size / ( 64 * 1024 );

		$hash_ctx = hash_init( 'sha256' );
		for ( $i = 0; $i < $chunks_count; $i++ ) {
			hash_update( $hash_ctx, $chunk );
		}
		$expected_hash = hash_final( $hash_ctx );

		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			array(
				array(
					'path'   => 'database/large.sql',
					'size'   => $payload_size,
					'sha256' => $expected_hash,
					'type'   => 'regular',
				),
			)
		);

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$manifest_raw = wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// database/large.sql sorts before manifest.json in byte order!
		$zip->addFromString( 'database/large.sql', str_repeat( $chunk, $chunks_count ) );
		$zip->setMtimeName( 'database/large.sql', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'database/large.sql', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'database/large.sql', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->close();

		$inspector = new PackageArchiveInspector();

		$mem_before = memory_get_usage();
		$result     = $inspector->inspect( $zip_path );
		$mem_diff   = memory_get_usage() - $mem_before;

		$this->assertTrue( $result->is_success(), 'Expected success: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		// Memory growth must remain bounded (well under 2 MB, e.g. < 500 KB for inspector data).
		$this->assertLessThan( 2 * 1024 * 1024, $mem_diff, 'Inspector memory must not scale with payload size.' );
	}

	/**
	 * Test all four migration modes with synthetic packages.
	 */
	public function test_all_four_migration_modes_inspect_successfully() {
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
				'site_count'   => 5,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::STANDALONE_TO_SUBSITE   => array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
		);

		$inspector = new PackageArchiveInspector();

		foreach ( $modes as $mode => $source ) {
			$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . $mode . '.zip';
			$manifest = new PackageManifest(
				$mode,
				$source,
				array(
					array(
						'path'   => 'database/mode_test.sql',
						'size'   => 4,
						'sha256' => hash( 'sha256', 'mode' ),
						'type'   => 'regular',
					),
				)
			);

			$this->create_synthetic_archive( $zip_path, $manifest, array( 'database/mode_test.sql' => 'mode' ) );
			$result = $inspector->inspect( $zip_path );

			$this->assertTrue( $result->is_success(), "Inspection failed for mode {$mode}: " . $result->get_error_message() );
			$this->assertSame( $mode, $result->get_manifest()->get_mode() );
		}
	}

	/**
	 * Test that inspect fails when ZipArchive::RDONLY capability is unavailable.
	 */
	public function test_inspect_fails_when_zip_readonly_capability_unavailable() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'dummy_rdonly.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $zip_path, 'PKfake' );

		$inspector = new class() extends PackageArchiveInspector {
			/**
			 * Stub ZipArchive::RDONLY capability.
			 *
			 * @return bool False.
			 */
			protected function is_zip_readonly_available() {
				return false;
			}
		};

		$result = $inspector->inspect( $zip_path );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_READONLY_UNAVAILABLE', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if a member has non-UNIX OS system.
	 */
	public function test_inspect_fails_when_member_opsys_is_not_unix() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'opsys_dos.zip';

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$manifest_raw = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":[{"path":"uploads/a.png","size":4,"sha256":"' . hash( 'sha256', 'data' ) . '","type":"regular"}]}';

		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'uploads/a.png', 'data' );
		$zip->setMtimeName( 'uploads/a.png', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'uploads/a.png', ZipArchive::CM_STORE );
		// Tamper host OS to DOS instead of UNIX.
		$zip->setExternalAttributesName( 'uploads/a.png', ZipArchive::OPSYS_DOS, 0x20 );
		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_METADATA_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if a member has non-canonical external attributes.
	 */
	public function test_inspect_fails_when_member_external_attributes_tampered() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'tampered_attrs.zip';

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$manifest_raw = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":[{"path":"uploads/a.png","size":4,"sha256":"' . hash( 'sha256', 'data' ) . '","type":"regular"}]}';

		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'uploads/a.png', 'data' );
		$zip->setMtimeName( 'uploads/a.png', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'uploads/a.png', ZipArchive::CM_STORE );
		// Tamper permission bits to 0777 instead of 0644.
		$zip->setExternalAttributesName( 'uploads/a.png', ZipArchive::OPSYS_UNIX, 0100777 << 16 );
		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_MEMBER_METADATA_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if an archive member is encrypted.
	 */
	public function test_inspect_fails_when_member_is_encrypted() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'encrypted.zip';

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->setPassword( 'secret' );
		$manifest_raw = '{"format_version":"1.0","mode":"standalone_to_subsite","source":{"is_multisite":false,"site_id":1,"base_prefix":"wp_"},"entries":[{"path":"uploads/a.png","size":4,"sha256":"' . hash( 'sha256', 'data' ) . '","type":"regular"}]}';

		$zip->addFromString( 'manifest.json', $manifest_raw );
		$zip->setMtimeName( 'manifest.json', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'manifest.json', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );

		$zip->addFromString( 'uploads/a.png', 'data' );
		$zip->setMtimeName( 'uploads/a.png', PackageArchiveInspector::DETERMINISTIC_MTIME );
		$zip->setCompressionName( 'uploads/a.png', ZipArchive::CM_STORE );
		$zip->setExternalAttributesName( 'uploads/a.png', ZipArchive::OPSYS_UNIX, PackageArchiveInspector::DETERMINISTIC_REGULAR_FILE_ATTR );
		if ( defined( 'ZipArchive::EM_AES_128' ) ) {
			$zip->setEncryptionName( 'uploads/a.png', ZipArchive::EM_AES_128 );
		}
		$zip->close();

		$inspector = new PackageArchiveInspector();
		$result    = $inspector->inspect( $zip_path );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_ENCRYPTION_PROHIBITED', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails closed when member encryption metadata is missing from stat.
	 */
	public function test_inspect_fails_when_member_encryption_metadata_missing() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'missing_enc_meta.zip';
		$payloads = array( 'uploads/a.png' => 'data' );
		$this->create_synthetic_archive( $zip_path, null, $payloads );

		$inspector = new class() extends PackageArchiveInspector {
			/**
			 * Stub stat_member to remove encryption_method.
			 *
			 * @param ZipArchive $zip   Zip instance.
			 * @param int        $index Member index.
			 * @return array<string, mixed>|false
			 */
			protected function stat_member( ZipArchive $zip, $index ) {
				$stat = parent::stat_member( $zip, $index );
				if ( is_array( $stat ) && array_key_exists( 'encryption_method', $stat ) ) {
					unset( $stat['encryption_method'] );
				}
				return $stat;
			}
		};

		$result = $inspector->inspect( $zip_path );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_ENCRYPTION_UNKNOWN', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails closed when member encryption metadata is not an integer.
	 */
	public function test_inspect_fails_when_member_encryption_metadata_not_integer() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'non_int_enc_meta.zip';
		$payloads = array( 'uploads/a.png' => 'data' );
		$this->create_synthetic_archive( $zip_path, null, $payloads );

		$inspector = new class() extends PackageArchiveInspector {
			/**
			 * Stub stat_member with non-integer encryption_method.
			 *
			 * @param ZipArchive $zip   Zip instance.
			 * @param int        $index Member index.
			 * @return array<string, mixed>|false
			 */
			protected function stat_member( ZipArchive $zip, $index ) {
				$stat = parent::stat_member( $zip, $index );
				if ( is_array( $stat ) ) {
					$stat['encryption_method'] = 'NONE';
				}
				return $stat;
			}
		};

		$result = $inspector->inspect( $zip_path );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_ENCRYPTION_UNKNOWN', $result->get_error_code() );
	}

	/**
	 * Test that inspect fails if the archive file changes during inspection.
	 */
	public function test_inspect_fails_when_archive_mutates_during_inspection() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'mutating.zip';
		$payloads = array( 'uploads/a.png' => 'data' );
		$this->create_synthetic_archive( $zip_path, null, $payloads );

		$inspector = new class( $zip_path ) extends PackageArchiveInspector {
			/**
			 * Path to target file.
			 *
			 * @var string
			 */
			private $target_path;

			/**
			 * Constructor.
			 *
			 * @param string $target_path Target path.
			 */
			public function __construct( $target_path ) {
				$this->target_path = $target_path;
			}

			/**
			 * Hook after ZIP verification.
			 *
			 * @return void
			 */
			protected function on_after_zip_verification() {
				// Mutate file bytes after ZIP verification before post-read hash.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $this->target_path, 'APPENDED_BYTES', FILE_APPEND );
			}
		};

		$result = $inspector->inspect( $zip_path );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ARCHIVE_CHANGED_DURING_INSPECTION', $result->get_error_code() );
	}

	/**
	 * Test that compute_file_sha256 fails closed if stream read stalls.
	 */
	public function test_compute_file_sha256_fails_closed_on_read_stall() {
		$zip_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'stall.zip';
		$payloads = array( 'uploads/a.png' => 'data' );
		$this->create_synthetic_archive( $zip_path, null, $payloads );

		$inspector = new class() extends PackageArchiveInspector {
			/**
			 * Simulate read chunk.
			 *
			 * @param resource $handle     Stream handle.
			 * @param int      $chunk_size Chunk size.
			 * @return string
			 */
			protected function read_stream_chunk( $handle, $chunk_size ) {
				// Simulate read stall: empty string returned without feof.
				return '';
			}
		};

		$result = $inspector->inspect( $zip_path );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ARCHIVE_READ_ERROR', $result->get_error_code() );
	}

	/**
	 * Test genuine writer-to-inspector compatibility using PackageArchiveWriter.
	 */
	public function test_genuine_writer_to_inspector_compatibility() {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$this->markTestSkipped( 'PackageArchiveWriter is intentionally disabled on Windows (fail-closed permissions policy).' );
		}

		$destination_zip = $this->temp_dir . DIRECTORY_SEPARATOR . 'writer_output.zip';
		$staging_dir     = $this->temp_dir . DIRECTORY_SEPARATOR . 'writer_staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$content  = 'Sample SQL database dump for writer-to-inspector integration.';
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
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

		// Use unmodified production PackageArchiveWriter.
		$writer       = new \SiteConvoy\Package\PackageArchiveWriter();
		$write_result = $writer->write(
			$manifest,
			array( 'database/posts.sql' => $stream ),
			$destination_zip,
			$staging_dir
		);

		$this->assertTrue( $write_result->is_success(), 'Writer must succeed: ' . wp_json_encode( $write_result->get_errors() ) );
		$this->assertFileExists( $destination_zip );

		// Now pass the writer-generated archive into the independent inspector.
		$inspector      = new PackageArchiveInspector();
		$inspect_result = $inspector->inspect( $destination_zip );

		$this->assertTrue( $inspect_result->is_success(), 'Inspector must succeed on writer output: ' . $inspect_result->get_error_message() );
		$this->assertSame( 1, $inspect_result->get_member_count() );
		$this->assertSame( $write_result->get_archive_size(), $inspect_result->get_archive_size() );
		$this->assertSame( $write_result->get_archive_sha256(), $inspect_result->get_archive_sha256() );
		$this->assertSame( $manifest->get_mode(), $inspect_result->get_manifest()->get_mode() );
	}
}
