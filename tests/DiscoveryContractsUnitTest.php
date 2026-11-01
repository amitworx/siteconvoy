<?php
/**
 * Unit tests for SiteConvoy contracts, finding shapes, and preflight logic.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use PHPUnit\Framework\TestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Contracts\PreflightFinding;
use SiteConvoy\Contracts\PreflightReport;

/**
 * Class DiscoveryContractsUnitTest
 */
class DiscoveryContractsUnitTest extends TestCase {

	/**
	 * Load plugin bootstrap before running contract unit tests.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once dirname( __DIR__ ) . '/siteconvoy.php';
	}

	/**
	 * Test that all four migration modes are defined and validated accurately.
	 */
	public function test_migration_mode_identifiers_and_validation() {
		$all_modes = MigrationMode::all();
		$this->assertCount( 4, $all_modes );
		$this->assertContains( MigrationMode::SUBSITE_TO_STANDALONE, $all_modes );
		$this->assertContains( MigrationMode::SUBSITE_TO_MULTISITE, $all_modes );
		$this->assertContains( MigrationMode::FULL_NETWORK_TO_NEW_ENV, $all_modes );
		$this->assertContains( MigrationMode::STANDALONE_TO_SUBSITE, $all_modes );

		$this->assertTrue( MigrationMode::is_valid( MigrationMode::SUBSITE_TO_STANDALONE ) );
		$this->assertTrue( MigrationMode::is_valid( MigrationMode::SUBSITE_TO_MULTISITE ) );
		$this->assertTrue( MigrationMode::is_valid( MigrationMode::FULL_NETWORK_TO_NEW_ENV ) );
		$this->assertTrue( MigrationMode::is_valid( MigrationMode::STANDALONE_TO_SUBSITE ) );

		$this->assertFalse( MigrationMode::is_valid( 'invalid_mode' ) );
		$this->assertFalse( MigrationMode::is_valid( '' ) );
	}

	/**
	 * Test source-type and site-ID requirements for each migration mode.
	 */
	public function test_migration_mode_source_and_site_id_requirements() {
		$this->assertTrue( MigrationMode::requires_multisite_source( MigrationMode::SUBSITE_TO_STANDALONE ) );
		$this->assertTrue( MigrationMode::requires_multisite_source( MigrationMode::SUBSITE_TO_MULTISITE ) );
		$this->assertTrue( MigrationMode::requires_multisite_source( MigrationMode::FULL_NETWORK_TO_NEW_ENV ) );
		$this->assertFalse( MigrationMode::requires_multisite_source( MigrationMode::STANDALONE_TO_SUBSITE ) );

		$this->assertTrue( MigrationMode::requires_source_site_id( MigrationMode::SUBSITE_TO_STANDALONE ) );
		$this->assertTrue( MigrationMode::requires_source_site_id( MigrationMode::SUBSITE_TO_MULTISITE ) );
		$this->assertFalse( MigrationMode::requires_source_site_id( MigrationMode::FULL_NETWORK_TO_NEW_ENV ) );
		$this->assertFalse( MigrationMode::requires_source_site_id( MigrationMode::STANDALONE_TO_SUBSITE ) );

		$this->assertSame( 'standalone', MigrationMode::expected_target_type( MigrationMode::SUBSITE_TO_STANDALONE ) );
		$this->assertSame( 'multisite_subsite', MigrationMode::expected_target_type( MigrationMode::SUBSITE_TO_MULTISITE ) );
		$this->assertSame( 'multisite_network', MigrationMode::expected_target_type( MigrationMode::FULL_NETWORK_TO_NEW_ENV ) );
		$this->assertSame( 'multisite_subsite', MigrationMode::expected_target_type( MigrationMode::STANDALONE_TO_SUBSITE ) );
	}

	/**
	 * Test PreflightFinding severity validation and JSON serialization.
	 */
	public function test_preflight_finding_structure_and_serialization() {
		$finding = new PreflightFinding(
			'SC_CUSTOM_USER_TABLE_UNSUPPORTED',
			PreflightFinding::SEVERITY_BLOCKER,
			'Custom user table constant CUSTOM_USER_TABLE is not supported.',
			array( 'constant' => 'CUSTOM_USER_TABLE' )
		);

		$this->assertSame( 'SC_CUSTOM_USER_TABLE_UNSUPPORTED', $finding->get_id() );
		$this->assertSame( PreflightFinding::SEVERITY_BLOCKER, $finding->get_severity() );
		$this->assertTrue( $finding->is_blocker() );

		$serialized = $finding->to_array();
		$this->assertSame( 'SC_CUSTOM_USER_TABLE_UNSUPPORTED', $serialized['id'] );
		$this->assertSame( 'blocker', $serialized['severity'] );
		$this->assertSame( 'Custom user table constant CUSTOM_USER_TABLE is not supported.', $serialized['message'] );
		$this->assertSame( array( 'constant' => 'CUSTOM_USER_TABLE' ), $serialized['context'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$json = json_encode( $finding );
		$this->assertNotFalse( $json );
		$decoded = json_decode( (string) $json, true );
		$this->assertSame( $serialized, $decoded );

		$this->expectException( \InvalidArgumentException::class );
		new PreflightFinding( 'SC_TEST', 'critical_invalid', 'Invalid severity.' );
	}

	/**
	 * Test PreflightReport enforces can_proceed_to_next_stage = false when blockers or unverified target exist.
	 */
	public function test_preflight_report_never_claims_ready_when_target_unverified_or_blockers_exist() {
		$info_finding = new PreflightFinding(
			'SC_TARGET_UNVERIFIED',
			PreflightFinding::SEVERITY_INFO,
			'Destination environment cannot be verified during read-only source preflight.'
		);

		// Even with zero blocker findings, unverified target verification must keep can_proceed_to_next_stage false.
		$report = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			2,
			PreflightReport::STATUS_SOURCE_INSPECTED,
			false,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			array( 'environment' => array( 'is_multisite' => true ) ),
			array( $info_finding )
		);

		$data = $report->to_array();
		$this->assertSame( PreflightReport::STATUS_SOURCE_INSPECTED, $data['status'] );
		$this->assertFalse( $data['can_proceed_to_next_stage'], 'Must be false when destination is unverified.' );
		$this->assertSame( 0, $data['counts']['blocker'] );
		$this->assertSame( 0, $data['counts']['warning'] );
		$this->assertSame( 1, $data['counts']['info'] );

		// If a blocker finding is present, can_proceed_to_next_stage is forced to false even if caller passed true.
		$blocker = new PreflightFinding(
			'SC_MODE_SOURCE_MISMATCH',
			PreflightFinding::SEVERITY_BLOCKER,
			'Cannot run subsite_to_standalone on a standalone WordPress install.'
		);

		$blocked_report = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			PreflightReport::STATUS_BLOCKED,
			true,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			null,
			array( $blocker )
		);

		$this->assertFalse( $blocked_report->can_proceed_to_next_stage() );
		$this->assertSame( 1, $blocked_report->to_array()['counts']['blocker'] );
	}
}
