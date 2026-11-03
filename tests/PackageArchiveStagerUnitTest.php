<?php
/**
 * Package Archive Stager Unit Tests.
 *
 * Verifies secure bounded-memory ZIP payload extraction into an isolated, private staging workspace,
 * strict canonical manifest compliance, SHA-256 verification, path containment, and fail-closed handling.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

require_once dirname( __DIR__ ) . '/siteconvoy.php';

use PHPUnit\Framework\TestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Package\PackageManifest;
use SiteConvoy\Package\PackageArchiveInspector;
use SiteConvoy\Package\PackageArchiveStager;
use SiteConvoy\Package\PackageArchiveStagingResult;
use SiteConvoy\Package\PackageArchiveWriter;
use ZipArchive;

/**
 * Class PackageArchiveStagerUnitTest
 */
class PackageArchiveStagerUnitTest extends TestCase {

	/**
	 * Temporary directory for tests.
	 *
	 * @var string
	 */
	private $temp_dir;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc_stager_test_' . uniqid();
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
	 * @param string                $path     Destination ZIP path.
	 * @param PackageManifest|null  $manifest Manifest object.
	 * @param array<string, string> $payloads Map of relative path => raw content.
	 * @param array<string, mixed>  $options  Overrides for testing.
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
	 * Check whether running on Windows.
	 *
	 * @return bool
	 */
	private function is_windows() {
		return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
	}

	/**
	 * Test that PackageArchiveStager and PackageArchiveStagingResult classes exist.
	 */
	public function test_classes_exist() {
		$this->assertTrue( class_exists( PackageArchiveStager::class ), 'PackageArchiveStager must exist.' );
		$this->assertTrue( class_exists( PackageArchiveStagingResult::class ), 'PackageArchiveStagingResult must exist.' );
	}

	/**
	 * Test platform gate: On Windows, stage() returns SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE immediately.
	 */
	public function test_stage_fails_closed_on_windows_platform() {
		if ( ! $this->is_windows() ) {
			$this->markTestSkipped( 'Native Windows test only.' );
		}

		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new PackageArchiveStager();
		$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE', $result->get_error_code() );
		// Ensure no files or subdirectories were created under staging_dir.
		$items = scandir( $staging_dir );
		$this->assertSame( array( '.', '..' ), $items );
	}

	/**
	 * Test platform gate via simulated Windows seam on POSIX.
	 */
	public function test_stage_fails_closed_when_simulating_windows_platform() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return true;
			}
		};

		$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE', $result->get_error_code() );
		$items = scandir( $staging_dir );
		$this->assertSame( array( '.', '..' ), $items );
	}

	/**
	 * Test capability checks: ZipArchive must exist.
	 */
	public function test_stage_fails_when_zip_unavailable() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub zip availability.
			 *
			 * @return bool
			 */
			protected function is_zip_available() {
				return false;
			}
		};

		$result = $stager->stage( $archive_path, $staging_dir, str_repeat( 'a', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_CAPABILITY_UNAVAILABLE', $result->get_error_code() );
	}

	/**
	 * Test capability checks: ZipArchive::RDONLY must exist.
	 */
	public function test_stage_fails_when_zip_readonly_unavailable() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub zip readonly availability.
			 *
			 * @return bool
			 */
			protected function is_zip_readonly_available() {
				return false;
			}
		};

		$result = $stager->stage( $archive_path, $staging_dir, str_repeat( 'a', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ZIP_READONLY_UNAVAILABLE', $result->get_error_code() );
	}

	/**
	 * Test input validation: invalid archive path.
	 */
	public function test_stage_fails_with_invalid_archive_path() {
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}
		};

		// Empty path.
		$result = $stager->stage( '', $staging_dir, str_repeat( 'a', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_ARCHIVE_PATH', $result->get_error_code() );

		// Stream wrapper path.
		$result = $stager->stage( 'php://memory', $staging_dir, str_repeat( 'a', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_ARCHIVE_PATH', $result->get_error_code() );

		// Non-existent path.
		$result = $stager->stage( $this->temp_dir . '/non_existent.zip', $staging_dir, str_repeat( 'a', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ARCHIVE_NOT_FOUND', $result->get_error_code() );
	}

	/**
	 * Test input validation: invalid expected digest format.
	 */
	public function test_stage_fails_with_invalid_expected_digest() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}
		};

		// Too short.
		$result = $stager->stage( $archive_path, $staging_dir, 'abc123' );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_EXPECTED_DIGEST', $result->get_error_code() );

		// Uppercase hex.
		$result = $stager->stage( $archive_path, $staging_dir, str_repeat( 'A', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_EXPECTED_DIGEST', $result->get_error_code() );

		// Non-hex chars.
		$result = $stager->stage( $archive_path, $staging_dir, str_repeat( 'z', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_EXPECTED_DIGEST', $result->get_error_code() );
	}

	/**
	 * Test validation: expected digest mismatch against inspected archive hash.
	 */
	public function test_stage_fails_when_expected_digest_does_not_match() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}
		};

		$result = $stager->stage( $archive_path, $staging_dir, str_repeat( '0', 64 ) );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ARCHIVE_DIGEST_MISMATCH', $result->get_error_code() );
	}

	/**
	 * Test staging parent validation: missing parent, not a directory, symlink, or permissive permissions.
	 */
	public function test_stage_fails_when_staging_parent_invalid() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}
		};

		// Empty staging dir.
		$result = $stager->stage( $archive_path, '', $expected_hash );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_STAGING_PARENT', $result->get_error_code() );

		// Non-existent staging parent.
		$non_existent = $this->temp_dir . DIRECTORY_SEPARATOR . 'does_not_exist';
		$result       = $stager->stage( $archive_path, $non_existent, $expected_hash );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'STAGING_PARENT_NOT_FOUND', $result->get_error_code() );

		// Staging parent is a regular file.
		$file_parent = $this->temp_dir . DIRECTORY_SEPARATOR . 'regular_file';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file_parent, 'hello' );
		$result = $stager->stage( $archive_path, $file_parent, $expected_hash );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'INVALID_STAGING_PARENT', $result->get_error_code() );
	}

	/**
	 * Test staging parent symlink rejection on POSIX.
	 */
	public function test_stage_fails_when_staging_parent_is_symlink() {
		if ( $this->is_windows() ) {
			$this->markTestSkipped( 'Symlink test requires POSIX environment.' );
		}

		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$real_staging = $this->temp_dir . DIRECTORY_SEPARATOR . 'real_staging';
		$symlink_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'sym_staging';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $real_staging, 0700, true );
		symlink( $real_staging, $symlink_dir );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new PackageArchiveStager();
		$result = $stager->stage( $archive_path, $symlink_dir, $expected_hash );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'SYMLINK_PATH_PROHIBITED', $result->get_error_code() );
	}

	/**
	 * Test staging parent permissive permissions rejection on POSIX (e.g. 0777 or 0755).
	 */
	public function test_stage_fails_when_staging_parent_is_permissive() {
		if ( $this->is_windows() ) {
			$this->markTestSkipped( 'POSIX permissions test.' );
		}

		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'permissive_staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0777, true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		chmod( $staging_dir, 0777 );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new PackageArchiveStager();
		$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );

		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'STAGING_PARENT_NOT_PRIVATE', $result->get_error_code() );
	}

	/**
	 * Test successful staging of synthetic ZIP package on POSIX.
	 */
	public function test_stage_successful_package_on_posix() {
		if ( $this->is_windows() ) {
			$this->markTestSkipped( 'POSIX only test.' );
		}

		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'valid.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$payloads = array(
			'database/schema.sql' => 'CREATE TABLE test (id INT);',
			'uploads/image.png'   => 'BINARY_PNG_DATA_HERE',
			'assets/empty.txt'    => '',
		);

		$this->create_synthetic_archive( $archive_path, null, $payloads );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new PackageArchiveStager();
		$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );

		$this->assertTrue( $result->is_success(), 'Stage failed: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		$this->assertSame( 3, $result->get_staged_count() );
		$this->assertSame( strlen( 'CREATE TABLE test (id INT);' ) + strlen( 'BINARY_PNG_DATA_HERE' ), $result->get_staged_bytes() );
		$this->assertSame( $expected_hash, $result->get_archive_sha256() );
		$this->assertInstanceOf( PackageManifest::class, $result->get_manifest() );

		$staging_root = $result->get_staging_root();
		$this->assertNotEmpty( $staging_root );
		$this->assertTrue( is_dir( $staging_root ) );
		// Verify staging root mode is 0700.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
		$this->assertSame( 0700, fileperms( $staging_root ) & 0777 );

		// Verify ledger and staged file content and permissions (0600).
		$ledger = $result->get_staged_ledger();
		$this->assertCount( 3, $ledger );

		foreach ( $payloads as $rel_path => $content ) {
			$this->assertArrayHasKey( $rel_path, $ledger );
			$staged_file = $ledger[ $rel_path ];
			$this->assertFileExists( $staged_file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertSame( $content, file_get_contents( $staged_file ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
			$this->assertSame( 0600, fileperms( $staged_file ) & 0777 );
		}

		// Ensure manifest.json is NOT staged as a file.
		$this->assertFileDoesNotExist( $staging_root . DIRECTORY_SEPARATOR . 'manifest.json' );
	}

	/**
	 * Test that permissive process umask (e.g. 0000) does NOT weaken staging directory or file permissions.
	 */
	public function test_stage_with_permissive_umask_preserves_private_permissions() {
		if ( $this->is_windows() ) {
			$this->markTestSkipped( 'POSIX only test.' );
		}

		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'valid.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$payloads = array(
			'database/schema.sql' => 'CREATE TABLE test (id INT);',
		);

		$this->create_synthetic_archive( $archive_path, null, $payloads );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$old_umask = umask( 0000 );
		try {
			$stager = new PackageArchiveStager();
			$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );
		} finally {
			umask( $old_umask );
		}

		$this->assertTrue( $result->is_success(), 'Stage failed: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		$staging_root = $result->get_staging_root();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
		$this->assertSame( 0700, fileperms( $staging_root ) & 0777 );

		$db_dir = $staging_root . DIRECTORY_SEPARATOR . 'database';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
		$this->assertSame( 0700, fileperms( $db_dir ) & 0777 );

		$file = $result->get_staged_ledger()['database/schema.sql'];
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fileperms
		$this->assertSame( 0600, fileperms( $file ) & 0777 );
	}

	/**
	 * Test roundtrip writer -> inspector -> stager across all 4 migration modes on POSIX.
	 */
	public function test_writer_to_inspector_to_stager_roundtrip() {
		if ( $this->is_windows() ) {
			$this->markTestSkipped( 'POSIX only test.' );
		}

		$modes = array(
			MigrationMode::STANDALONE_TO_SUBSITE   => array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::SUBSITE_TO_STANDALONE   => array(
				'is_multisite' => true,
				'site_id'      => 2,
				'network_id'   => 1,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::SUBSITE_TO_MULTISITE    => array(
				'is_multisite' => true,
				'site_id'      => 2,
				'network_id'   => 1,
				'base_prefix'  => 'wp_',
			),
			MigrationMode::FULL_NETWORK_TO_NEW_ENV => array(
				'is_multisite' => true,
				'site_id'      => null,
				'network_id'   => 1,
				'base_prefix'  => 'wp_',
			),
		);

		foreach ( $modes as $mode => $source_meta ) {
			$mode_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'mode_' . $mode;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $mode_dir, 0700, true );

			$writer_staging = $mode_dir . DIRECTORY_SEPARATOR . 'writer_staging';
			$stager_staging = $mode_dir . DIRECTORY_SEPARATOR . 'stager_staging';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $writer_staging, 0700, true );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			mkdir( $stager_staging, 0700, true );

			$zip_path = $mode_dir . DIRECTORY_SEPARATOR . 'archive.zip';

			$payload1 = 'SQL CONTENT FOR ' . $mode;
			$payload2 = 'ASSET DATA FOR ' . $mode;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$stream1 = fopen( 'php://memory', 'r+b' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $stream1, $payload1 );
			rewind( $stream1 );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$stream2 = fopen( 'php://memory', 'r+b' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $stream2, $payload2 );
			rewind( $stream2 );

			$entries = array(
				array(
					'path'   => 'database/schema.sql',
					'size'   => strlen( $payload1 ),
					'sha256' => hash( 'sha256', $payload1 ),
					'type'   => 'regular',
				),
				array(
					'path'   => 'assets/file.txt',
					'size'   => strlen( $payload2 ),
					'sha256' => hash( 'sha256', $payload2 ),
					'type'   => 'regular',
				),
			);

			$manifest = new PackageManifest(
				$mode,
				$source_meta,
				$entries
			);

			$writer = new PackageArchiveWriter();
			$w_res  = $writer->write(
				$manifest,
				array(
					'database/schema.sql' => $stream1,
					'assets/file.txt'     => $stream2,
				),
				$zip_path,
				$writer_staging
			);

			$this->assertTrue( $w_res->is_success(), 'Writer failed for mode: ' . $mode );

			// Inspect archive.
			$inspector = new PackageArchiveInspector();
			$i_res     = $inspector->inspect( $zip_path );
			$this->assertTrue( $i_res->is_success(), 'Inspector failed for mode: ' . $mode );

			// Stage archive.
			$stager = new PackageArchiveStager();
			$s_res  = $stager->stage( $zip_path, $stager_staging, $i_res->get_archive_sha256() );
			$this->assertTrue( $s_res->is_success(), 'Stager failed for mode: ' . $mode );

			$this->assertSame( $mode, $s_res->get_manifest()->get_mode() );
			$ledger = $s_res->get_staged_ledger();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertSame( $payload1, file_get_contents( $ledger['database/schema.sql'] ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertSame( $payload2, file_get_contents( $ledger['assets/file.txt'] ) );
		}
	}

	/**
	 * Test failure cleanup: cleans up owned stage directory and does NOT delete caller sentinel files.
	 */
	public function test_failure_cleanup_removes_only_operation_owned_files() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'corrupt_stream.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		// Create caller sentinel file in staging_dir.
		$sentinel = $staging_dir . DIRECTORY_SEPARATOR . 'sentinel.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $sentinel, 'caller data' );

		// Create archive with mismatched declared size so copy fails during stream extraction.
		$payloads = array( 'database/schema.sql' => 'CORRUPTED' );
		$entries  = array(
			array(
				'path'   => 'database/schema.sql',
				'size'   => 999999, // Mismatched size.
				'sha256' => hash( 'sha256', 'CORRUPTED' ),
				'type'   => 'regular',
			),
		);
		$manifest = new PackageManifest(
			MigrationMode::STANDALONE_TO_SUBSITE,
			array(
				'is_multisite' => false,
				'site_id'      => 1,
				'base_prefix'  => 'wp_',
			),
			$entries
		);

		$this->create_synthetic_archive( $archive_path, $manifest, $payloads );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}
		};

		$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );
		$this->assertFalse( $result->is_success() );

		// Caller sentinel must still exist.
		$this->assertFileExists( $sentinel );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 'caller data', file_get_contents( $sentinel ) );

		// Only sentinel should remain in staging_dir.
		$remaining = array_values( array_diff( scandir( $staging_dir ), array( '.', '..' ) ) );
		$this->assertSame( array( 'sentinel.txt' ), $remaining );
	}

	/**
	 * Test detection of archive modification during staging.
	 */
	public function test_stage_fails_when_archive_mutated_during_staging() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		$this->create_synthetic_archive( $archive_path, null, array( 'database/schema.sql' => 'SELECT 1;' ) );
		$expected_hash = hash_file( 'sha256', $archive_path );

		$stager = new class( $archive_path ) extends PackageArchiveStager {
			/**
			 * Archive to mutate.
			 *
			 * @var string
			 */
			private $archive_to_mutate;

			/**
			 * Constructor.
			 *
			 * @param string $path Archive path.
			 */
			public function __construct( $path ) {
				parent::__construct();
				$this->archive_to_mutate = $path;
			}

			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}

			/**
			 * Hook after payload staging to mutate source archive.
			 */
			protected function on_after_payload_staging() {
				// Mutate archive file after extraction.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $this->archive_to_mutate, 'CORRUPTED_AFTER_STAGING', FILE_APPEND );
				clearstatcache( true, $this->archive_to_mutate );
			}
		};

		$result = $stager->stage( $archive_path, $staging_dir, $expected_hash );
		$this->assertFalse( $result->is_success() );
		$this->assertSame( 'ARCHIVE_CHANGED_DURING_STAGING', $result->get_error_code() );

		// Staging dir should be cleaned up.
		$items = scandir( $staging_dir );
		$this->assertSame( array( '.', '..' ), $items );
	}

	/**
	 * Test bounded memory usage during staging of a multi-megabyte synthetic entry.
	 */
	public function test_stage_bounded_memory_for_large_payload() {
		$archive_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'large.zip';
		$staging_dir  = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );

		// 4 MB synthetic stream payload.
		$chunk_4mb = str_repeat( 'X', 4 * 1024 * 1024 );
		$this->create_synthetic_archive( $archive_path, null, array( 'database/large.sql' => $chunk_4mb ) );
		$expected_hash = hash_file( 'sha256', $archive_path );
		unset( $chunk_4mb );

		$stager = new class() extends PackageArchiveStager {
			/**
			 * Stub windows platform check.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}

			/**
			 * Stub staging parent privacy check.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			protected function is_staging_parent_private( $path ) {
				return true;
			}
		};

		$mem_before = memory_get_usage( true );
		$result     = $stager->stage( $archive_path, $staging_dir, $expected_hash );
		$mem_after  = memory_get_usage( true );

		$this->assertTrue( $result->is_success(), 'Stage failed: ' . $result->get_error_code() . ': ' . $result->get_error_message() );
		$this->assertSame( 1, $result->get_staged_count() );
		$this->assertSame( 4 * 1024 * 1024, $result->get_staged_bytes() );

		// Memory growth during 4MB streaming must be strictly bounded (< 3 MB).
		$mem_diff = $mem_after - $mem_before;
		$this->assertLessThan( 3 * 1024 * 1024, $mem_diff, 'Staging large entry must not buffer entirety in memory.' );
	}
}
