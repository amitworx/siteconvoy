<?php
/**
 * Package Archive Writer Unit Tests.
 *
 * Verifies standard ZIP archive creation, bounded-memory streaming, SHA-256 verification,
 * manifest encoding, deterministic contents, temp workspace security, and failure cleanup.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

require_once dirname( __DIR__ ) . '/siteconvoy.php';

use PHPUnit\Framework\TestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Package\PackageManifest;
use SiteConvoy\Package\PackageArchiveWriter;
use SiteConvoy\Package\PackageArchiveWriterResult;
use ZipArchive;

/**
 * Class PackageArchiveWriterUnitTest
 */
class PackageArchiveWriterUnitTest extends TestCase {

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
		$this->temp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sc_writer_test_' . uniqid();
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
			if ( is_link( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->recursive_remove_dir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}

	/**
	 * Helper: Create a synthetic in-memory stream with given content.
	 *
	 * @param string $content Content string.
	 * @return resource
	 */
	private function create_stream( $content ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $stream, $content );
		rewind( $stream );
		return $stream;
	}

	/**
	 * Helper: Create a test writer instance simulating POSIX environment for portable unit testing.
	 *
	 * @param int $chunk_size Chunk size.
	 * @return PackageArchiveWriter
	 */
	private function create_writer( $chunk_size = PackageArchiveWriter::DEFAULT_CHUNK_SIZE ) {
		return new class( $chunk_size ) extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
		};
	}

	/**
	 * Test class exists and can be instantiated.
	 */
	public function test_writer_instantiation() {
		$writer = $this->create_writer();
		$this->assertInstanceOf( PackageArchiveWriter::class, $writer );
	}

	/**
	 * Test writing a valid ZIP package with synthetic payloads.
	 */
	public function test_write_valid_synthetic_package() {
		$content1 = 'CREATE TABLE wp_posts (id INT);';
		$content2 = 'media-file-bytes-12345';
		$content3 = '';

		$entries = array(
			array(
				'path'   => 'database/wp_posts.sql',
				'size'   => strlen( $content1 ),
				'sha256' => hash( 'sha256', $content1 ),
				'type'   => 'regular',
			),
			array(
				'path'   => 'uploads/photo.jpg',
				'size'   => strlen( $content2 ),
				'sha256' => hash( 'sha256', $content2 ),
				'type'   => 'regular',
			),
			array(
				'path'   => 'assets/empty.txt',
				'size'   => strlen( $content3 ),
				'sha256' => hash( 'sha256', $content3 ),
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

		$streams = array(
			'database/wp_posts.sql' => $this->create_stream( $content1 ),
			'uploads/photo.jpg'     => $this->create_stream( $content2 ),
			'assets/empty.txt'      => $this->create_stream( $content3 ),
		);

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'package.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'staging';

		$writer = $this->create_writer();
		$result = $writer->write( $manifest, $streams, $output_path, $staging_dir );

		$this->assertInstanceOf( PackageArchiveWriterResult::class, $result );
		$this->assertTrue( $result->is_success(), 'Writer must succeed: ' . wp_json_encode( $result->get_errors() ) );
		$this->assertFileExists( $output_path );

		// Open via ZipArchive independently and verify contents.
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $output_path ) );

		// Exact 4 entries: manifest.json + 3 declared payload entries.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$this->assertSame( 4, $zip->numFiles );

		$names = array();
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		// Deterministic sorted order.
		$this->assertSame(
			array( 'assets/empty.txt', 'database/wp_posts.sql', 'manifest.json', 'uploads/photo.jpg' ),
			$names
		);

		// Verify manifest.json matches schema and payload.
		$manifest_json = $zip->getFromName( 'manifest.json' );
		$this->assertIsString( $manifest_json );
		$manifest_data = json_decode( $manifest_json, true );
		$this->assertSame( '1.0', $manifest_data['format_version'] );
		$this->assertSame( MigrationMode::STANDALONE_TO_SUBSITE, $manifest_data['mode'] );
		$this->assertSame( 3, count( $manifest_data['entries'] ) );

		// Verify member bytes.
		$this->assertSame( $content1, $zip->getFromName( 'database/wp_posts.sql' ) );
		$this->assertSame( $content2, $zip->getFromName( 'uploads/photo.jpg' ) );
		$this->assertSame( $content3, $zip->getFromName( 'assets/empty.txt' ) );

		$zip->close();

		// Ensure caller staging parent remains and contains no leftover temporary files.
		$this->assertDirectoryExists( $staging_dir );
		$this->assertEmpty( glob( $staging_dir . DIRECTORY_SEPARATOR . 'sc_op_*' ), 'Per-operation subdirectory must be cleaned up.' );
	}

	/**
	 * Test all four migration modes produce valid ZIP packages with synthetic payloads.
	 */
	public function test_all_four_modes_produce_valid_packages() {
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

		$writer = $this->create_writer();

		foreach ( $modes as $mode => $source ) {
			$content  = "DUMP FOR MODE {$mode}";
			$entries  = array(
				array(
					'path'   => 'database/dump.sql',
					'size'   => strlen( $content ),
					'sha256' => hash( 'sha256', $content ),
				),
			);
			$manifest = new PackageManifest( $mode, $source, $entries );
			$streams  = array(
				'database/dump.sql' => $this->create_stream( $content ),
			);

			$pkg_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'pkg_' . $mode . '.zip';
			$stg_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'stg_' . $mode;

			$res = $writer->write( $manifest, $streams, $pkg_path, $stg_path );
			$this->assertTrue( $res->is_success(), "Failed writing package for mode {$mode}" );
			$this->assertFileExists( $pkg_path );

			$zip = new ZipArchive();
			$this->assertTrue( $zip->open( $pkg_path ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->assertSame( 2, $zip->numFiles );
			$manifest_data = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
			$this->assertSame( $mode, $manifest_data['mode'] );
			$zip->close();
		}
	}

	/**
	 * Test deterministic byte reproducibility across repeated package writes.
	 */
	public function test_deterministic_byte_reproducibility() {
		$c1 = 'payload-part-1';
		$c2 = 'payload-part-2';

		$entries = array(
			array(
				'path'   => 'database/posts.sql',
				'size'   => strlen( $c1 ),
				'sha256' => hash( 'sha256', $c1 ),
			),
			array(
				'path'   => 'uploads/file.png',
				'size'   => strlen( $c2 ),
				'sha256' => hash( 'sha256', $c2 ),
			),
		);

		$source = array(
			'is_multisite' => false,
			'site_id'      => 1,
			'base_prefix'  => 'wp_',
		);

		$writer = $this->create_writer();

		$m1 = new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $source, $entries );
		$s1 = array(
			'database/posts.sql' => $this->create_stream( $c1 ),
			'uploads/file.png'   => $this->create_stream( $c2 ),
		);
		$p1 = $this->temp_dir . DIRECTORY_SEPARATOR . 'pkg1.zip';
		$r1 = $writer->write( $m1, $s1, $p1, $this->temp_dir . DIRECTORY_SEPARATOR . 'stg1' );
		$this->assertTrue( $r1->is_success() );

		// Sleep 1 second to ensure non-deterministic clocks would have differed.
		sleep( 1 );

		$m2 = new PackageManifest( MigrationMode::STANDALONE_TO_SUBSITE, $source, $entries );
		$s2 = array(
			'database/posts.sql' => $this->create_stream( $c1 ),
			'uploads/file.png'   => $this->create_stream( $c2 ),
		);
		$p2 = $this->temp_dir . DIRECTORY_SEPARATOR . 'pkg2.zip';
		$r2 = $writer->write( $m2, $s2, $p2, $this->temp_dir . DIRECTORY_SEPARATOR . 'stg2' );
		$this->assertTrue( $r2->is_success() );

		$hash1 = hash_file( 'sha256', $p1 );
		$hash2 = hash_file( 'sha256', $p2 );
		$this->assertSame( $hash1, $hash2, 'ZIP output must be bit-for-bit deterministic.' );
	}

	/**
	 * Test bounded memory streaming on multi-megabyte synthetic payloads.
	 */
	public function test_multi_megabyte_payload_bounded_memory() {
		// 4 MB synthetic payload stream.
		$chunk_pattern = str_repeat( 'A', 65536 ); // 64 KB pattern
		$total_chunks  = 64; // 64 * 64 KB = 4 MB
		$total_bytes   = $total_chunks * 65536;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$stream   = fopen( 'php://temp', 'r+' );
		$hash_ctx = hash_init( 'sha256' );
		for ( $i = 0; $i < $total_chunks; $i++ ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $stream, $chunk_pattern );
			hash_update( $hash_ctx, $chunk_pattern );
		}
		rewind( $stream );
		$expected_hash = hash_final( $hash_ctx );

		$entries = array(
			array(
				'path'   => 'database/large_dump.sql',
				'size'   => $total_bytes,
				'sha256' => $expected_hash,
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

		$pkg_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'large.zip';
		$stg_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'large_stg';

		$mem_before = memory_get_usage();

		$writer = $this->create_writer( 65536 );
		$res    = $writer->write( $manifest, array( 'database/large_dump.sql' => $stream ), $pkg_path, $stg_path );

		$mem_after = memory_get_usage();

		$this->assertTrue( $res->is_success() );
		$this->assertFileExists( $pkg_path );

		// The memory delta should be well under 4 MB (e.g. under 1.5 MB for metadata/ZipArchive overhead).
		$mem_delta = $mem_after - $mem_before;
		$this->assertLessThan( 2000000, $mem_delta, 'Memory usage must remain bounded during streaming.' );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $pkg_path ) );
		$stat = $zip->statName( 'database/large_dump.sql' );
		$this->assertSame( $total_bytes, $stat['size'] );
		$zip->close();
	}

	/**
	 * Test no-clobber behavior: will not overwrite existing destination file.
	 */
	public function test_will_not_overwrite_existing_destination() {
		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'existing.zip';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $output_path, 'CANNOT_TOUCH' );

		$entries  = array(
			array(
				'path'   => 'assets/file.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( 'assets/file.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$this->temp_dir . DIRECTORY_SEPARATOR . 'stg'
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'DESTINATION_ALREADY_EXISTS', $res->get_errors()[0]['code'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 'CANNOT_TOUCH', file_get_contents( $output_path ) );
	}

	/**
	 * Test failure on hash corruption, cleanup of temporary staging files, and no partial archive published.
	 */
	public function test_hash_corruption_fails_and_cleans_up() {
		$content  = 'actual-bytes';
		$bad_hash = hash( 'sha256', 'different-bytes' );

		$entries  = array(
			array(
				'path'   => 'database/dump.sql',
				'size'   => strlen( $content ),
				'sha256' => $bad_hash,
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'corrupt.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'corrupt_stg';

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( 'database/dump.sql' => $this->create_stream( $content ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'CHECKSUM_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
		$this->assertDirectoryExists( $staging_dir );
		$this->assertEmpty( glob( $staging_dir . DIRECTORY_SEPARATOR . 'sc_op_*' ), 'Operation subdirectory must be cleaned up on failure.' );
	}

	/**
	 * Test failure on stream size mismatch (stream has fewer bytes than declared).
	 */
	public function test_short_stream_fails_and_cleans_up() {
		$entries  = array(
			array(
				'path'   => 'database/dump.sql',
				'size'   => 100, // Declared 100 bytes, stream only has 5 bytes.
				'sha256' => hash( 'sha256', 'hello' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'short.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'short_stg';

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( 'database/dump.sql' => $this->create_stream( 'hello' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'SIZE_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
		$this->assertDirectoryExists( $staging_dir );
		$this->assertEmpty( glob( $staging_dir . DIRECTORY_SEPARATOR . 'sc_op_*' ), 'Operation subdirectory must be cleaned up on failure.' );
	}

	/**
	 * Test missing stream fails before writing.
	 */
	public function test_missing_stream_fails() {
		$entries  = array(
			array(
				'path'   => 'database/dump.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'missing.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'missing_stg';

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array(), // Empty stream map.
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'STREAM_MISSING', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test unexpected extra stream in stream map fails.
	 */
	public function test_unexpected_stream_fails() {
		$entries  = array(
			array(
				'path'   => 'database/dump.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'extra.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'extra_stg';

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array(
				'database/dump.sql' => $this->create_stream( 'hello' ),
				'assets/extra.txt'  => $this->create_stream( 'unexpected' ),
			),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'UNEXPECTED_STREAM', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test duplicate stream handle across multiple entries fails.
	 */
	public function test_duplicate_stream_handle_fails() {
		$entries  = array(
			array(
				'path'   => 'database/dump1.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
			),
			array(
				'path'   => 'database/dump2.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$shared_stream = $this->create_stream( 'hello' );

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'dup.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'dup_stg';

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array(
				'database/dump1.sql' => $shared_stream,
				'database/dump2.sql' => $shared_stream,
			),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'DUPLICATE_STREAM_RESOURCE', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test write-only stream handle is rejected.
	 */
	public function test_write_only_stream_is_rejected() {
		$tmp_file = tempnam( sys_get_temp_dir(), 'sc_wo_' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$write_only = fopen( $tmp_file, 'w' );

		$entries  = array(
			array(
				'path'   => 'database/dump.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( 'database/dump.sql' => $write_only ),
			$this->temp_dir . DIRECTORY_SEPARATOR . 'wo.zip',
			$this->temp_dir . DIRECTORY_SEPARATOR . 'wo_stg'
		);

		if ( file_exists( $tmp_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $tmp_file );
		}

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'UNREADABLE_STREAM', $res->get_errors()[0]['code'] );
	}

	/**
	 * Test close_streams option behaves as requested.
	 */
	public function test_close_streams_flag_behavior() {
		$s1       = $this->create_stream( 'hello' );
		$entries  = array(
			array(
				'path'   => 'database/dump.sql',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( 'database/dump.sql' => $s1 ),
			$this->temp_dir . DIRECTORY_SEPARATOR . 'close_false.zip',
			$this->temp_dir . DIRECTORY_SEPARATOR . 'close_stg',
			false // close_streams = false.
		);

		$this->assertTrue( $res->is_success() );
		$this->assertTrue( is_resource( $s1 ), 'Stream handle should remain open when close_streams=false.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $s1 );
	}

	/**
	 * Test detection and rejection of symlink member in finalized ZIP before publication.
	 */
	public function test_rejection_of_symlink_member_before_publication() {
		// Create a test writer subclass that injects a symlink into the temp zip before close.
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Hook before closing zip to inject a symlink attribute.
			 *
			 * @param ZipArchive $zip Zip archive instance.
			 * @param string     $temp_archive_path Temporary zip path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Inject a symlink attribute on assets/empty.txt.
				$zip->setExternalAttributesName( 'assets/empty.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'symlink_test.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'symlink_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_SYMLINK_MEMBER_PROHIBITED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test detection and rejection of unexpected member in finalized ZIP before publication.
	 */
	public function test_rejection_of_unexpected_member_before_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Hook before closing zip to inject an unexpected member.
			 *
			 * @param ZipArchive $zip Zip archive instance.
			 * @param string     $temp_archive_path Temporary zip path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Inject an extraneous member into the zip.
				$zip->addFromString( 'assets/injected.txt', 'malicious payload' );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'unexpected_test.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'unexpected_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_MEMBER_COUNT_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test detection and rejection of directory member in finalized ZIP before publication.
	 */
	public function test_rejection_of_directory_member_before_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Hook before closing zip to inject a directory member.
			 *
			 * @param ZipArchive $zip Zip archive instance.
			 * @param string     $temp_archive_path Temporary zip path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Inject a directory entry into the zip.
				$zip->addEmptyDir( 'uploads/nested/' );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'directory_test.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'directory_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test failure simulation when ZIP close fails.
	 */
	public function test_zip_close_failure_simulation() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate zip close failure.
			 *
			 * @param ZipArchive $zip Zip archive instance.
			 * @return bool
			 */
			protected function execute_zip_close( ZipArchive $zip ) {
				// Close the zip object properly first so PHP/libzip can destroy context, then return false.
				$zip->close();
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'close_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'close_fail_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_CLOSE_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test non-ASCII filename support in ZIP writer when runtime extensions are present.
	 */
	public function test_unicode_entry_path_in_zip_package() {
		if ( ! function_exists( 'normalizer_is_normalized' ) || ! defined( 'Normalizer::FORM_C' ) ) {
			$this->markTestSkipped( 'PHP intl extension not loaded in this environment.' );
		}

		$unicode_path = 'uploads/2026/01/café-photo.jpg';
		$content      = 'unicode-image-bytes';

		$entries  = array(
			array(
				'path'   => $unicode_path,
				'size'   => strlen( $content ),
				'sha256' => hash( 'sha256', $content ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'unicode.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'unicode_stg';

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( $unicode_path => $this->create_stream( $content ) ),
			$output_path,
			$staging_dir
		);

		$this->assertTrue( $res->is_success(), 'Unicode package writing must succeed when extensions available.' );
		$this->assertFileExists( $output_path );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $output_path ) );
		$this->assertSame( $content, $zip->getFromName( $unicode_path ) );
		$zip->close();
	}

	/**
	 * Test safe rejection when ZipArchive is unavailable.
	 */
	public function test_ziparchive_unavailable_safe_handling() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate unavailable zip extension.
			 *
			 * @return bool
			 */
			protected function is_zip_available() {
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'no_zip.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'no_zip_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_CAPABILITY_UNAVAILABLE', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test that staging directory parent sentinels and external files are preserved on success and failure.
	 */
	public function test_staging_parent_sentinels_preserved_on_success_and_failure() {
		$staging_parent = $this->temp_dir . DIRECTORY_SEPARATOR . 'caller_workspace';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_parent, 0700, true );

		// Prepopulate caller staging parent with sentinel file and nested subdirectory.
		$sentinel_file = $staging_parent . DIRECTORY_SEPARATOR . 'caller_data.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $sentinel_file, 'CRITICAL_CALLER_DATA' );

		$sentinel_subdir = $staging_parent . DIRECTORY_SEPARATOR . 'caller_subdir';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $sentinel_subdir, 0700, true );
		$sentinel_subfile = $sentinel_subdir . DIRECTORY_SEPARATOR . 'nested.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $sentinel_subfile, 'NESTED_DATA' );

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'success.zip';
		$writer      = $this->create_writer();

		// 1. Success case.
		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_parent
		);

		$this->assertTrue( $res->is_success() );
		$this->assertFileExists( $sentinel_file, 'Caller sentinel file must not be erased on success.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 'CRITICAL_CALLER_DATA', file_get_contents( $sentinel_file ) );
		$this->assertFileExists( $sentinel_subfile, 'Caller nested file must not be erased on success.' );

		// 2. Failure case (corrupt hash).
		$fail_output = $this->temp_dir . DIRECTORY_SEPARATOR . 'fail.zip';
		$res_fail    = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( 'corrupted_bytes' ) ),
			$fail_output,
			$staging_parent
		);

		$this->assertFalse( $res_fail->is_success() );
		$this->assertFileExists( $sentinel_file, 'Caller sentinel file must not be erased on failure.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 'CRITICAL_CALLER_DATA', file_get_contents( $sentinel_file ) );
		$this->assertFileExists( $sentinel_subfile, 'Caller nested file must not be erased on failure.' );
		$this->assertDirectoryExists( $staging_parent, 'Caller staging parent directory itself must remain intact.' );
	}

	/**
	 * Test late destination race condition: destination file created before publication must not be clobbered.
	 */
	public function test_late_destination_creation_prevents_clobber_and_cleans_up() {
		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'raced_output.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'raced_stg';

		// Subclass writer that creates a destination file right before finalize/publish.
		$writer = new class( $output_path ) extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Target output destination file.
			 *
			 * @var string
			 */
			private $target_output;

			/**
			 * Constructor.
			 *
			 * @param string $target_output Target output file.
			 */
			public function __construct( $target_output ) {
				parent::__construct();
				$this->target_output = $target_output;
			}

			/**
			 * Hook before close zip to simulate racing process creating destination file.
			 *
			 * @param ZipArchive $zip Zip instance.
			 * @param string     $temp_archive_path Temporary archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $this->target_output, 'RACED_DESTINATION_PAYLOAD_MUST_NOT_OVERWRITE' );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success(), 'Publication must fail when destination is created by a racing process.' );
		$this->assertSame( 'DESTINATION_ALREADY_EXISTS', $res->get_errors()[0]['code'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertSame( 'RACED_DESTINATION_PAYLOAD_MUST_NOT_OVERWRITE', file_get_contents( $output_path ) );
	}

	/**
	 * Test rejection when finalized ZIP manifest is altered (e.g. Mode or source changed).
	 */
	public function test_finalized_zip_manifest_modification_rejected() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Hook before close zip to inject an altered manifest.json.
			 *
			 * @param ZipArchive $zip Zip instance.
			 * @param string     $temp_archive_path Temporary archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Tamper with manifest.json: alter migration mode to an unexpected mode with valid topology.
				$tampered_manifest = array(
					'format_version' => '1.0',
					'mode'           => MigrationMode::FULL_NETWORK_TO_NEW_ENV,
					'source'         => array(
						'is_multisite' => true,
						'network_id'   => 1,
						'site_count'   => 10,
						'base_prefix'  => 'wp_',
					),
					'entries'        => array(
						array(
							'path'   => 'assets/empty.txt',
							'size'   => 0,
							'sha256' => hash( 'sha256', '' ),
						),
					),
				);
				$zip->addFromString( 'manifest.json', (string) wp_json_encode( $tampered_manifest ) );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'tampered.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'tampered_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'FINAL_MANIFEST_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test rejection when destination path or staging parent path has ancestor symlinks.
	 */
	public function test_symlinked_destination_ancestor_rejected() {
		// Create a real directory and a symlink to it.
		$real_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'real_dir';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $real_dir, 0700, true );

		$link_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'link_dir';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! function_exists( 'symlink' ) || false === @symlink( $real_dir, $link_dir ) ) {
			$this->markTestSkipped( 'symlink() function not supported or permitted on this system.' );
		}

		$output_path = $link_dir . DIRECTORY_SEPARATOR . 'test.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'stg';

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$writer = $this->create_writer();
		$res    = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'SYMLINK_PATH_PROHIBITED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test rejection when manifest.json exceeds maximum size limit.
	 */
	public function test_manifest_exceeding_size_limit_rejected() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Hook before close zip to inject an oversized manifest.json.
			 *
			 * @param ZipArchive $zip Zip instance.
			 * @param string     $temp_archive_path Temporary archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Create a manifest of > 2MB.
				$oversized_str = str_repeat( ' ', 2097153 );
				$zip->addFromString( 'manifest.json', '{"format_version":"1.0","padding":"' . $oversized_str . '"}' );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'oversized_manifest.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'oversized_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'MANIFEST_EXCEEDS_SIZE_LIMIT', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test rejection when atomic publication primitive is unavailable or fails.
	 */
	public function test_atomic_publication_failure_simulation() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Override publish primitive to simulate atomic publication failure.
			 *
			 * @param string $source_path Source temp path.
			 * @param string $destination_path Destination path.
			 * @return array{success: bool, code: string, message: string}
			 */
			protected function publish_archive_no_clobber( $source_path, $destination_path ) {
				return array(
					'success' => false,
					'code'    => 'ATOMIC_PUBLICATION_UNAVAILABLE',
					'message' => 'Simulated publication primitive failure.',
				);
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'atomic_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'atomic_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ATOMIC_PUBLICATION_UNAVAILABLE', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
		$this->assertEmpty( glob( $staging_dir . DIRECTORY_SEPARATOR . 'sc_op_*' ), 'Staging files must be cleaned up on publish failure.' );
	}

	/**
	 * Test random bytes failure handling and zero orphaned staging directories.
	 */
	public function test_random_bytes_failure_does_not_orphan_directory() {
		// Test random failure on second nonce (temp archive name after op directory created).
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Call count counter.
			 *
			 * @var int
			 */
			private $call_count = 0;

			/**
			 * Simulate random failure on second call.
			 *
			 * @param int $length Number of bytes.
			 * @return string
			 * @throws \Exception When simulated RNG failure occurs.
			 */
			protected function generate_random_bytes( $length ) {
				++$this->call_count;
				if ( 2 === $this->call_count ) {
					throw new \Exception( 'Simulated RNG failure.' );
				}
				return parent::generate_random_bytes( $length );
			}
		};

		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'rng_fail_stg';
		// Prepopulate sentinel to verify caller workspace preserved.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		mkdir( $staging_dir, 0700, true );
		$sentinel = $staging_dir . DIRECTORY_SEPARATOR . 'sentinel.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $sentinel, 'CALLER_SENTINEL' );

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'rng_fail.zip';
		$res         = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'RANDOM_BYTES_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
		$this->assertFileExists( $sentinel );
		$this->assertEmpty( glob( $staging_dir . DIRECTORY_SEPARATOR . 'sc_op_*' ), 'Operation subdirectory must be removed on RNG failure.' );
	}

	/**
	 * Test failure when setMtimeName or setCompressionName fails on archive member.
	 */
	public function test_zip_metadata_failure_blocks_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate metadata operation failure on zip member.
			 *
			 * @param ZipArchive $zip Zip archive.
			 * @param string     $name Member name.
			 * @param int        $method Compression method.
			 * @return bool
			 */
			protected function set_member_compression( ZipArchive $zip, $name, $method ) {
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'meta_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'meta_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_SET_METADATA_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test that tampered manifest with reversed entry order is rejected byte-for-byte.
	 */
	public function test_reversed_manifest_entries_rejected_by_raw_bytes_comparison() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Hook before close zip to inject an embedded manifest with reversed entries.
			 *
			 * @param ZipArchive $zip Zip instance.
			 * @param string     $temp_archive_path Temporary archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Reverse the entries array.
				$tampered = array(
					'format_version' => '1.0',
					'mode'           => MigrationMode::STANDALONE_TO_SUBSITE,
					'source'         => array(
						'is_multisite' => false,
						'site_id'      => 1,
						'base_prefix'  => 'wp_',
					),
					'entries'        => array(
						array(
							'path'   => 'database/b.sql',
							'size'   => 0,
							'sha256' => hash( 'sha256', '' ),
						),
						array(
							'path'   => 'database/a.sql',
							'size'   => 0,
							'sha256' => hash( 'sha256', '' ),
						),
					),
				);
				$zip->addFromString( 'manifest.json', (string) wp_json_encode( $tampered, JSON_UNESCAPED_SLASHES ) );
			}
		};

		$entries  = array(
			array(
				'path'   => 'database/a.sql',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
			),
			array(
				'path'   => 'database/b.sql',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'reversed_manifest.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'reversed_stg';

		$res = $writer->write(
			$manifest,
			array(
				'database/a.sql' => $this->create_stream( '' ),
				'database/b.sql' => $this->create_stream( '' ),
			),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'FINAL_MANIFEST_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test pre-publication digest failure blocks archive publication.
	 */
	public function test_pre_publication_digest_failure_blocks_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate hash failure before publication.
			 *
			 * @param string $algo Hash algorithm.
			 * @param string $path File path.
			 * @return string|false
			 */
			protected function compute_archive_hash( $algo, $path ) {
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/empty.txt',
				'size'   => 0,
				'sha256' => hash( 'sha256', '' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'hash_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'hash_fail_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/empty.txt' => $this->create_stream( '' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ARCHIVE_DIGEST_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test archive file permissions are restricted to owner-only (0600) on POSIX platforms.
	 */
	public function test_published_archive_has_restricted_owner_permissions() {
		$writer = $this->create_writer();

		$entries  = array(
			array(
				'path'   => 'assets/secure.txt',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'perms.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'perms_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/secure.txt' => $this->create_stream( 'hello' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertTrue( $res->is_success() );
		$this->assertFileExists( $output_path );

		if ( 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			// On POSIX, assert permissions are strictly 0600.
			$perms = fileperms( $output_path ) & 0777;
			$this->assertSame( 0600, $perms, sprintf( 'Expected 0600 permissions, got %o', $perms ) );
		}
	}

	/**
	 * Test that permissive process umask does not cause published archive to have permissive permissions on POSIX.
	 */
	public function test_permissive_umask_does_not_compromise_archive_permissions() {
		if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) || ! function_exists( 'umask' ) ) {
			$this->markTestSkipped( 'umask test requires POSIX environment.' );
		}

		$old_umask = umask( 0000 ); // Completely permissive umask.

		try {
			$writer = $this->create_writer();

			$entries  = array(
				array(
					'path'   => 'assets/secure.txt',
					'size'   => 5,
					'sha256' => hash( 'sha256', 'hello' ),
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

			$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'umask_perms.zip';
			$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'umask_perms_stg';

			$res = $writer->write(
				$manifest,
				array( 'assets/secure.txt' => $this->create_stream( 'hello' ) ),
				$output_path,
				$staging_dir
			);

			$this->assertTrue( $res->is_success() );
			$this->assertFileExists( $output_path );

			$perms = fileperms( $output_path ) & 0777;
			$this->assertSame( 0600, $perms, sprintf( 'Archive must strictly have 0600 permissions even under 0000 umask, got %o', $perms ) );
		} finally {
			umask( $old_umask );
		}
	}

	/**
	 * Test failure to enforce archive permissions blocks publication.
	 */
	public function test_archive_permissions_enforcement_failure_blocks_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate permission enforcement failure.
			 *
			 * @param string $path File path.
			 * @param int    $mode Mode.
			 * @return bool
			 */
			protected function enforce_file_permissions( $path, $mode ) {
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/secure.txt',
				'size'   => 5,
				'sha256' => hash( 'sha256', 'hello' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'perm_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'perm_fail_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/secure.txt' => $this->create_stream( 'hello' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ARCHIVE_PERMISSIONS_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test pre-publication verifier rejects archive if member external attributes are missing or non-regular.
	 */
	public function test_final_zip_verifier_rejects_missing_or_invalid_external_attributes() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Alter member external attributes before close.
			 *
			 * @param ZipArchive $zip ZipArchive instance.
			 * @param string     $temp_archive_path Temp archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				// Corrupt external attributes for manifest.json: set directory attribute instead of regular file.
				$zip->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, 0040755 << 16 );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_attr.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_attr_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_NONREGULAR_MEMBER_PROHIBITED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test pre-publication verifier rejects archive if member external attributes cannot be determined or are missing.
	 */
	public function test_final_zip_verifier_rejects_missing_external_attributes_lookup() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate lookup failure for member external attributes.
			 *
			 * @param ZipArchive $zip Zip instance.
			 * @param int        $index Member index.
			 * @param int        $opsys Target OS.
			 * @param int        $attr Target attributes.
			 * @return bool
			 */
			protected function get_member_external_attributes( ZipArchive $zip, $index, &$opsys, &$attr ) {
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'no_attr.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'no_attr_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_STAT_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test pre-publication verifier rejects archive if member timestamp was tampered or corrupted.
	 */
	public function test_final_zip_verifier_rejects_tampered_member_timestamp() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Alter member timestamp before close.
			 *
			 * @param ZipArchive $zip ZipArchive instance.
			 * @param string     $temp_archive_path Temp archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				$zip->setMtimeName( 'manifest.json', 1234567890 );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_mtime.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_mtime_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_MEMBER_METADATA_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test pre-publication verifier rejects archive if member compression method was tampered or corrupted.
	 */
	public function test_final_zip_verifier_rejects_tampered_member_compression() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Alter member compression before close.
			 *
			 * @param ZipArchive $zip ZipArchive instance.
			 * @param string     $temp_archive_path Temp archive path.
			 */
			protected function before_close_zip( ZipArchive $zip, $temp_archive_path ) {
				$zip->setCompressionName( 'manifest.json', ZipArchive::CM_DEFLATE );
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_comp.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'bad_comp_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_MEMBER_METADATA_MISMATCH', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test setting external attributes failure blocks publication.
	 */
	public function test_setting_external_attributes_failure_blocks_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate POSIX environment.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return false;
			}
			/**
			 * Simulate failure to set external attributes.
			 *
			 * @param ZipArchive $zip Zip instance.
			 * @param string     $name Member name.
			 * @param int        $opsys OS system.
			 * @param int        $attr External attributes.
			 * @return bool
			 */
			protected function set_member_external_attributes( ZipArchive $zip, $name, $opsys, $attr ) {
				return false;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'ext_attr_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'ext_attr_fail_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'ZIP_SET_METADATA_FAILED', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
	}

	/**
	 * Test Windows platform fail-closed policy returns SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE.
	 */
	public function test_windows_platform_fails_closed_before_staging_or_publication() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate Windows platform.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return true;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'win_fail.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'win_fail_stg';
		$stream      = $this->create_stream( 'test' );

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $stream ),
			$output_path,
			$staging_dir,
			true
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
		$this->assertFileDoesNotExist( $staging_dir );
		$this->assertFalse( is_resource( $stream ), 'Streams must be closed when close_streams is true.' );
	}

	/**
	 * Test Windows platform failure preserves caller stream when close_streams is false.
	 */
	public function test_windows_platform_failure_preserves_stream_when_close_streams_false() {
		$writer = new class() extends PackageArchiveWriter {
			/**
			 * Simulate Windows platform.
			 *
			 * @return bool
			 */
			protected function is_windows_platform() {
				return true;
			}
		};

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'win_fail_no_close.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'win_fail_no_close_stg';
		$stream      = $this->create_stream( 'test' );

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $stream ),
			$output_path,
			$staging_dir,
			false
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE', $res->get_errors()[0]['code'] );
		$this->assertTrue( is_resource( $stream ), 'Streams must be preserved when close_streams is false.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream );
	}

	/**
	 * Test real runtime host behavior on Windows fails closed without seam.
	 */
	public function test_real_windows_host_fails_closed_when_running_on_windows() {
		if ( 'WIN' !== strtoupper( substr( PHP_OS, 0, 3 ) ) ) {
			$this->markTestSkipped( 'Test runs only on genuine Windows hosts.' );
		}

		$writer = new PackageArchiveWriter();

		$entries  = array(
			array(
				'path'   => 'assets/test.txt',
				'size'   => 4,
				'sha256' => hash( 'sha256', 'test' ),
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

		$output_path = $this->temp_dir . DIRECTORY_SEPARATOR . 'real_win.zip';
		$staging_dir = $this->temp_dir . DIRECTORY_SEPARATOR . 'real_win_stg';

		$res = $writer->write(
			$manifest,
			array( 'assets/test.txt' => $this->create_stream( 'test' ) ),
			$output_path,
			$staging_dir
		);

		$this->assertFalse( $res->is_success() );
		$this->assertSame( 'SECURE_FILESYSTEM_PERMISSIONS_UNAVAILABLE', $res->get_errors()[0]['code'] );
		$this->assertFileDoesNotExist( $output_path );
		$this->assertFileDoesNotExist( $staging_dir );
	}
}
