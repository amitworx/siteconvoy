<?php
/**
 * Unit tests for SiteConvoy MigrationPlan contract, JSON serialization, and deterministic scope structure.
 *
 * @package SiteConvoy
 */

namespace SiteConvoy\Tests;

use PHPUnit\Framework\TestCase;
use SiteConvoy\Contracts\MigrationMode;
use SiteConvoy\Contracts\MigrationPlan;
use SiteConvoy\Contracts\PreflightFinding;
use SiteConvoy\Contracts\PreflightReport;

/**
 * Class MigrationPlanUnitTest
 */
class MigrationPlanUnitTest extends TestCase {

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
	 * Test MigrationPlan instantiation, getters, and fail-closed non-executable state.
	 */
	public function test_migration_plan_instantiation_and_invariants() {
		$target_expectations = array(
			'expected_type'       => 'standalone',
			'verification_status' => 'unverified',
		);

		$preflight_report = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			2,
			PreflightReport::STATUS_SOURCE_INSPECTED,
			false,
			$target_expectations,
			array(),
			array()
		);

		$tables = array(
			'blog'       => array(
				array(
					'logical_name' => 'posts',
					'table_name'   => 'wp_2_posts',
					'status'       => 'included',
					'scope'        => 'blog',
					'reason'       => 'Core blog table for site ID 2.',
				),
			),
			'global'     => array(
				array(
					'logical_name' => 'users',
					'table_name'   => 'wp_users',
					'status'       => 'excluded',
					'scope'        => 'global',
					'reason'       => 'Global user tables require explicit selective review and are excluded from automatic subsite transfer.',
				),
			),
			'ms_global'  => array(
				array(
					'logical_name' => 'blogs',
					'table_name'   => 'wp_blogs',
					'status'       => 'excluded',
					'scope'        => 'ms_global',
					'reason'       => 'Multisite network governance table excluded from subsite migration.',
				),
			),
			'unresolved' => array(),
		);

		$dependencies = array(
			'themes'  => array(
				array(
					'slug'   => 'twentytwentyfour',
					'status' => 'review_required',
					'parent' => null,
				),
			),
			'plugins' => array(),
			'dropins' => array(),
			'media'   => array(
				'layout'             => 'sites_id',
				'offload_indicators' => array(),
			),
		);

		$plan = new MigrationPlan(
			MigrationMode::SUBSITE_TO_STANDALONE,
			2,
			'draft',
			$tables,
			$dependencies,
			$preflight_report,
			array(
				'is_multisite'       => true,
				'current_network_id' => 1,
				'base_prefix'        => 'wp_',
				'site_count'         => 1,
			)
		);

		$this->assertSame( MigrationPlan::SCHEMA_VERSION, $plan->get_schema_version() );
		$this->assertSame( MigrationMode::SUBSITE_TO_STANDALONE, $plan->get_mode() );
		$this->assertSame( 2, $plan->get_source_site_id() );
		$this->assertSame( 'draft', $plan->get_status() );
		$this->assertFalse( $plan->is_executable(), 'Migration plan must NEVER be executable at planning stage.' );

		$data = $plan->to_array();
		$this->assertSame( MigrationPlan::SCHEMA_VERSION, $data['schema_version'] );
		$this->assertSame( MigrationMode::SUBSITE_TO_STANDALONE, $data['mode'] );
		$this->assertSame( 2, $data['source_site_id'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertFalse( $data['is_executable'] );
		$this->assertArrayHasKey( 'tables', $data );
		$this->assertArrayHasKey( 'dependencies', $data );
		$this->assertArrayHasKey( 'preflight', $data );
		$this->assertArrayHasKey( 'source_topology', $data );
		$this->assertArrayHasKey( 'outstanding_verifications', $data );
		$this->assertNotEmpty( $data['outstanding_verifications'] );

		// Serialization check.
		$json = wp_json_encode( $plan );
		$this->assertNotFalse( $json );
		$decoded = json_decode( (string) $json, true );
		$this->assertSame( $data, $decoded );
	}

	/**
	 * Test that blocked preflight report forces plan status to 'blocked' and non-executable.
	 */
	public function test_blocked_preflight_forces_blocked_plan_status() {
		$finding = new PreflightFinding(
			'SC_SOURCE_SITE_NOT_FOUND',
			PreflightFinding::SEVERITY_BLOCKER,
			'Requested site was not found.'
		);

		$preflight_report = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			999,
			PreflightReport::STATUS_BLOCKED,
			false,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			null,
			array( $finding )
		);

		$plan = new MigrationPlan(
			MigrationMode::SUBSITE_TO_STANDALONE,
			999,
			'draft',
			array(
				'blog'       => array(),
				'global'     => array(),
				'ms_global'  => array(),
				'unresolved' => array(),
			),
			array(
				'themes'  => array(),
				'plugins' => array(),
				'dropins' => array(),
				'media'   => array(),
			),
			$preflight_report,
			array(
				'is_multisite'       => true,
				'current_network_id' => 1,
				'base_prefix'        => 'wp_',
				'site_count'         => 0,
			)
		);

		$this->assertSame( 'blocked', $plan->get_status(), 'Blocked preflight must force plan status to blocked.' );
		$this->assertFalse( $plan->is_executable() );
	}

	/**
	 * Test that unauthorized preflight report forces plan status to 'unauthorized'.
	 */
	public function test_unauthorized_preflight_forces_unauthorized_plan_status() {
		$finding = new PreflightFinding(
			'SC_AUTH_FORBIDDEN',
			PreflightFinding::SEVERITY_BLOCKER,
			'Current user lacks required capability.'
		);

		$preflight_report = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			PreflightReport::STATUS_UNAUTHORIZED,
			false,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			null,
			array( $finding )
		);

		$plan = new MigrationPlan(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			'draft',
			array(),
			array(),
			$preflight_report,
			array()
		);

		$this->assertSame( 'unauthorized', $plan->get_status() );
		$this->assertFalse( $plan->is_executable() );
		$this->assertEmpty( $plan->to_array()['tables'] );
	}

	/**
	 * Test deterministic JSON serialization and key ordering.
	 */
	public function test_deterministic_json_serialization() {
		$preflight_report = new PreflightReport(
			MigrationMode::STANDALONE_TO_SUBSITE,
			null,
			PreflightReport::STATUS_SOURCE_INSPECTED,
			false,
			array(
				'expected_type'       => 'multisite_subsite',
				'verification_status' => 'unverified',
			),
			array(),
			array()
		);

		$tables = array(
			'blog'       => array(
				array(
					'logical_name' => 'posts',
					'scope'        => 'blog',
					'status'       => 'included',
					'table_name'   => 'wp_posts',
					'reason'       => 'Core blog table for standalone site.',
				),
			),
			'global'     => array(
				array(
					'logical_name' => 'users',
					'scope'        => 'global',
					'status'       => 'needs_review',
					'table_name'   => 'wp_users',
					'reason'       => 'User identity requires collision review in target multisite network.',
				),
			),
			'ms_global'  => array(),
			'unresolved' => array(),
		);

		$plan1 = new MigrationPlan(
			MigrationMode::STANDALONE_TO_SUBSITE,
			null,
			'needs_review',
			$tables,
			array(
				'dropins' => array(),
				'media'   => array(
					'layout'             => 'standard',
					'offload_indicators' => array(),
				),
				'plugins' => array(),
				'themes'  => array(),
			),
			$preflight_report,
			array(
				'base_prefix'        => 'wp_',
				'current_network_id' => 1,
				'is_multisite'       => false,
				'site_count'         => 1,
			)
		);

		$plan2 = new MigrationPlan(
			MigrationMode::STANDALONE_TO_SUBSITE,
			null,
			'needs_review',
			$tables,
			array(
				'dropins' => array(),
				'media'   => array(
					'layout'             => 'standard',
					'offload_indicators' => array(),
				),
				'plugins' => array(),
				'themes'  => array(),
			),
			$preflight_report,
			array(
				'base_prefix'        => 'wp_',
				'current_network_id' => 1,
				'is_multisite'       => false,
				'site_count'         => 1,
			)
		);

		$json1 = wp_json_encode( $plan1 );
		$json2 = wp_json_encode( $plan2 );

		$this->assertSame( $json1, $json2, 'Plan JSON output must be completely deterministic.' );
	}
	/**
	 * Test that MigrationPlan accepts and outputs mu_plugins and per-site dependencies in its contract.
	 */
	public function test_migration_plan_contract_includes_mu_plugins_and_per_site_dependencies() {
		$preflight_report = new PreflightReport(
			MigrationMode::FULL_NETWORK_TO_NEW_ENV,
			null,
			PreflightReport::STATUS_SOURCE_INSPECTED,
			false,
			array(
				'expected_type'       => 'multisite_network',
				'verification_status' => 'unverified',
			),
			array(),
			array()
		);

		$dependencies = array(
			'themes'     => array(
				array(
					'slug'   => 'twentytwentyfour',
					'status' => 'review_required',
					'parent' => null,
				),
			),
			'plugins'    => array(
				array(
					'slug'     => 'siteconvoy-test-sitewide/siteconvoy-test-sitewide.php',
					'sitewide' => true,
					'status'   => 'review_required',
				),
			),
			'mu_plugins' => array(
				array(
					'filename' => 'security-check.php',
					'status'   => 'review_required',
				),
			),
			'dropins'    => array(
				array(
					'filename' => 'object-cache.php',
					'status'   => 'review_required',
				),
			),
			'media'      => array(
				'layout'             => 'sites_id',
				'offload_indicators' => array(),
			),
			'per_site'   => array(
				2 => array(
					'theme'   => array(
						'stylesheet' => 'twentytwentyfour-child',
						'template'   => 'twentytwentyfour',
						'is_child'   => true,
					),
					'plugins' => array( 'site-specific-plugin/site-specific.php' ),
				),
			),
		);

		$plan = new MigrationPlan(
			MigrationMode::FULL_NETWORK_TO_NEW_ENV,
			null,
			'needs_review',
			array(
				'blog'       => array(),
				'global'     => array(),
				'ms_global'  => array(),
				'unresolved' => array(),
			),
			$dependencies,
			$preflight_report,
			array(
				'is_multisite'       => true,
				'current_network_id' => 1,
				'base_prefix'        => 'wp_',
				'site_count'         => 2,
			)
		);

		$deps = $plan->get_dependencies();
		$this->assertArrayHasKey( 'mu_plugins', $deps );
		$this->assertCount( 1, $deps['mu_plugins'] );
		$this->assertSame( 'security-check.php', $deps['mu_plugins'][0]['filename'] );
		$this->assertArrayHasKey( 'per_site', $deps );
		$this->assertArrayHasKey( 2, $deps['per_site'] );
		$this->assertTrue( $deps['per_site'][2]['theme']['is_child'] );
	}
}
