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
		$tables = (array) $plan->to_array()['tables'];
		$this->assertEmpty( $tables );
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

	/**
	 * Test that serialized MigrationPlan conform strictly to the schema specification in schemas/migration-plan.json
	 * across representative standalone/multisite, authorized/unauthorized, and blocked plans.
	 */
	public function test_serialized_migration_plan_conforms_to_canonical_json_schema() {
		$schema_file = dirname( __DIR__ ) . '/schemas/migration-plan.json';
		$this->assertFileExists( $schema_file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw_schema = file_get_contents( $schema_file );
		$this->assertNotFalse( $raw_schema );
		$schema = json_decode( $raw_schema, true );
		$this->assertIsArray( $schema );

		$required_root = $schema['required'];
		$this->assertContains( 'schema_version', $required_root );
		$this->assertContains( 'mode', $required_root );
		$this->assertContains( 'source_site_id', $required_root );
		$this->assertContains( 'status', $required_root );
		$this->assertContains( 'is_executable', $required_root );
		$this->assertContains( 'source_topology', $required_root );
		$this->assertContains( 'tables', $required_root );
		$this->assertContains( 'dependencies', $required_root );
		$this->assertContains( 'outstanding_verifications', $required_root );
		$this->assertContains( 'preflight', $required_root );

		// 1. Authorized Subsite Plan.
		$preflight1 = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			2,
			PreflightReport::STATUS_SOURCE_INSPECTED,
			false,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			array(),
			array()
		);
		$plan1      = new MigrationPlan(
			MigrationMode::SUBSITE_TO_STANDALONE,
			2,
			'needs_review',
			array(
				'blog'       => array(
					array(
						'logical_name' => 'posts',
						'table_name'   => 'wp_2_posts',
						'scope'        => 'blog',
						'status'       => 'included',
						'reason'       => 'Core blog table.',
					),
				),
				'global'     => array(
					array(
						'logical_name' => 'users',
						'table_name'   => 'wp_users',
						'scope'        => 'global',
						'status'       => 'excluded',
						'reason'       => 'Shared users excluded.',
					),
				),
				'ms_global'  => array(),
				'unresolved' => array(),
			),
			array(
				'themes'     => array(),
				'plugins'    => array(),
				'mu_plugins' => array(),
				'dropins'    => array(),
				'media'      => array(
					'layout'             => 'standard',
					'offload_indicators' => array(),
				),
			),
			$preflight1,
			array(
				'is_multisite'       => true,
				'current_network_id' => 1,
				'base_prefix'        => 'wp_',
				'site_count'         => 2,
			)
		);

		// 2. Unauthorized Plan.
		$finding_unauth = new PreflightFinding( 'SC_AUTH_FORBIDDEN', PreflightFinding::SEVERITY_BLOCKER, 'Unauthorized.' );
		$preflight2     = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			PreflightReport::STATUS_UNAUTHORIZED,
			false,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			null,
			array( $finding_unauth )
		);
		$plan2          = new MigrationPlan(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			'draft',
			array(),
			array(),
			$preflight2,
			array()
		);

		// 3. Early Blocked Plan (Blocked preflight before inventory, e.g. multi-network or unsupported environment).
		$finding_early_blocked = new PreflightFinding( 'SC_MULTI_NETWORK_UNSUPPORTED', PreflightFinding::SEVERITY_BLOCKER, 'Multi-network installation is not supported.' );
		$preflight3            = new PreflightReport(
			MigrationMode::FULL_NETWORK_TO_NEW_ENV,
			null,
			PreflightReport::STATUS_BLOCKED,
			false,
			array(
				'expected_type'       => 'multisite_network',
				'verification_status' => 'unverified',
			),
			null,
			array( $finding_early_blocked )
		);
		$plan3                 = new MigrationPlan(
			MigrationMode::FULL_NETWORK_TO_NEW_ENV,
			null,
			'blocked',
			array(
				'blog'       => array(),
				'global'     => array(),
				'ms_global'  => array(),
				'unresolved' => array(),
			),
			array(
				'themes'     => array(),
				'plugins'    => array(),
				'mu_plugins' => array(),
				'dropins'    => array(),
				'media'      => array(
					'layout'             => 'standard',
					'offload_indicators' => array(),
				),
			),
			$preflight3,
			array()
		);

		// 4. Post-Planning Blocked Plan (Missing tables).
		$finding_blocked = new PreflightFinding( 'SC_MISSING_CORE_TABLES_PLAN', PreflightFinding::SEVERITY_BLOCKER, 'Missing tables.' );
		$preflight4      = new PreflightReport(
			MigrationMode::FULL_NETWORK_TO_NEW_ENV,
			null,
			PreflightReport::STATUS_BLOCKED,
			false,
			array(
				'expected_type'       => 'multisite_network',
				'verification_status' => 'unverified',
			),
			array(),
			array( $finding_blocked )
		);
		$plan4           = new MigrationPlan(
			MigrationMode::FULL_NETWORK_TO_NEW_ENV,
			null,
			'blocked',
			array(
				'blog'       => array(),
				'global'     => array(),
				'ms_global'  => array(),
				'unresolved' => array(),
			),
			array(
				'themes'     => array(),
				'plugins'    => array(),
				'mu_plugins' => array(),
				'dropins'    => array(),
				'media'      => array(
					'layout'             => 'standard',
					'offload_indicators' => array(),
				),
			),
			$preflight4,
			array(
				'is_multisite'       => true,
				'current_network_id' => 1,
				'base_prefix'        => 'wp_',
				'site_count'         => 1,
			)
		);

		$test_plans = array( $plan1, $plan2, $plan3, $plan4 );

		foreach ( $test_plans as $idx => $p ) {
			$json_str = (string) wp_json_encode( $p );
			$this->assertNotFalse( $json_str );
			$decoded = json_decode( $json_str, true );
			$this->assertIsArray( $decoded );

			// Strict recursive schema validation using type-preserving json_decode.
			$decoded_preserve = json_decode( $json_str );
			$errors           = JsonSchemaValidator::validate( $decoded_preserve, $schema );
			$this->assertEmpty( $errors, sprintf( "Plan index %d failed JSON schema validation:\n%s", $idx, implode( "\n", $errors ) ) );

			// Check no sensitive credential patterns leak in serialized JSON.
			$this->assertStringNotContainsString( 'db_password', $json_str );
			$this->assertStringNotContainsString( 'user_pass', $json_str );
			$this->assertStringNotContainsString( 'NONCE_KEY', $json_str );
			$this->assertStringNotContainsString( 'AUTH_KEY', $json_str );
		}
	}

	/**
	 * Negative-control tests verifying that JsonSchemaValidator rejects mismatched types
	 * and nested empty arrays where objects are required (and vice versa).
	 */
	public function test_json_schema_validator_rejects_type_mismatches_and_array_object_confusion() {
		$schema = array(
			'type'       => 'object',
			'required'   => array( 'empty_obj', 'empty_arr', 'str_val' ),
			'properties' => array(
				'empty_obj' => array(
					'type'          => 'object',
					'maxProperties' => 0,
				),
				'empty_arr' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'str_val'   => array(
					'type' => 'string',
				),
			),
		);

		// Valid payload: empty_obj is {}, empty_arr is [], str_val is string.
		$valid_json = '{"empty_obj":{},"empty_arr":[],"str_val":"ok"}';
		$valid_data = json_decode( $valid_json );
		$this->assertEmpty( JsonSchemaValidator::validate( $valid_data, $schema ) );

		// Negative control 1: empty_obj is [] instead of {}.
		$invalid_json1 = '{"empty_obj":[],"empty_arr":[],"str_val":"ok"}';
		$invalid_data1 = json_decode( $invalid_json1 );
		$errors1       = JsonSchemaValidator::validate( $invalid_data1, $schema );
		$this->assertNotEmpty( $errors1 );
		$this->assertStringContainsString( '$.empty_obj: expected type [object], got array', implode( '; ', $errors1 ) );

		// Negative control 2: empty_arr is {} instead of [].
		$invalid_json2 = '{"empty_obj":{},"empty_arr":{},"str_val":"ok"}';
		$invalid_data2 = json_decode( $invalid_json2 );
		$errors2       = JsonSchemaValidator::validate( $invalid_data2, $schema );
		$this->assertNotEmpty( $errors2 );
		$this->assertStringContainsString( '$.empty_arr: expected type [array], got object', implode( '; ', $errors2 ) );

		// Negative control 3: nested [] in unauthorized plan structure (tables, dependencies, source_topology).
		$canonical_schema_path = dirname( __DIR__ ) . '/schemas/migration-plan.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$canonical_schema = json_decode( (string) file_get_contents( $canonical_schema_path ), true );

		$finding_unauth    = new PreflightFinding( 'SC_AUTH_FORBIDDEN', PreflightFinding::SEVERITY_BLOCKER, 'Lacks capability.' );
		$preflight_unauth  = new PreflightReport(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			PreflightReport::STATUS_UNAUTHORIZED,
			false,
			array(
				'expected_type'       => 'standalone',
				'verification_status' => 'unverified',
			),
			null,
			array( $finding_unauth )
		);
		$valid_unauth_plan = new MigrationPlan(
			MigrationMode::SUBSITE_TO_STANDALONE,
			1,
			'draft',
			array(),
			array(),
			$preflight_unauth,
			array()
		);

		$valid_unauth_json = (string) wp_json_encode( $valid_unauth_plan );
		$this->assertEmpty( JsonSchemaValidator::validate( json_decode( $valid_unauth_json ), $canonical_schema ) );

		// Injected regression: replace "tables":{} with "tables":[] in serialized JSON.
		$mutated_unauth_json = str_replace( '"tables":{}', '"tables":[]', $valid_unauth_json );
		$this->assertNotSame( $valid_unauth_json, $mutated_unauth_json );
		$errors_mutated = JsonSchemaValidator::validate( json_decode( $mutated_unauth_json ), $canonical_schema );
		$this->assertNotEmpty( $errors_mutated, 'Nested empty array [] for tables must fail canonical schema expecting object {}.' );
		$this->assertStringContainsString( '$.tables: expected type [object], got array', implode( '; ', $errors_mutated ) );
	}
}
