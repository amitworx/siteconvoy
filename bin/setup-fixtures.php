<?php
/**
 * Deterministic Synthetic Fixture Provisioning Script for SiteConvoy Local Environments.
 *
 * Executed via WP-CLI:
 *   wp eval-file wp-content/plugins/siteconvoy/bin/setup-fixtures.php allow-test-fixtures
 *
 * Fail-closed safety guards require:
 *   1. Execution via WP-CLI.
 *   2. Explicit opt-in argument 'allow-test-fixtures' or env SITECONVOY_ALLOW_TEST_FIXTURES=1.
 *   3. Confirmed local/test environment type (wp_get_environment_type() in {'local', 'development', 'test'}).
 *   4. Confirmed local/synthetic host domain (localhost, 127.0.0.1, *.local, *.test, example.org).
 *
 * Uses only synthetic test data. Never runs against production.
 *
 * @package SiteConvoy
 */

/**
 * Check whether a hostname is a safe local or RFC 2606 / 6761 test hostname.
 *
 * @param string $host Hostname to validate.
 * @return bool True if safe local/test host, false otherwise.
 */
function siteconvoy_is_safe_fixture_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( '' === $host ) {
		return false;
	}

	$exact_allowed = array( 'localhost', '127.0.0.1', 'example.org', 'example.com', 'example.net' );
	if ( in_array( $host, $exact_allowed, true ) ) {
		return true;
	}

	$suffix_allowed = array( '.localhost', '.local', '.test', '.example.org', '.example.com', '.invalid' );
	foreach ( $suffix_allowed as $suffix ) {
		if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}

	return false;
}

/**
 * Validate whether the current environment is safe for synthetic fixture provisioning.
 *
 * Fails closed unless:
 *   1. Explicit opt-in ('allow-test-fixtures' in $cli_args or $env_opt_in === true).
 *   2. $env_type is in {'local', 'development', 'test'}.
 *   3. Both $home_host and $site_host are safe local/RFC-reserved test hostnames.
 *
 * @param array  $cli_args   Positional arguments passed to the script.
 * @param bool   $env_opt_in Whether SITECONVOY_ALLOW_TEST_FIXTURES is enabled.
 * @param string $env_type   WordPress environment type (e.g. from wp_get_environment_type()).
 * @param string $home_host  Host parsed from home_url().
 * @param string $site_host  Host parsed from site_url().
 * @return array{allowed: bool, reason: string} Validation result and human-readable failure reason.
 */
function siteconvoy_validate_fixture_context( array $cli_args, $env_opt_in, $env_type, $home_host, $site_host ) {
	$has_opt_in = in_array( 'allow-test-fixtures', $cli_args, true ) || true === $env_opt_in;
	if ( ! $has_opt_in ) {
		return array(
			'allowed' => false,
			'reason'  => 'Refusing to provision synthetic fixtures without explicit opt-in. ' .
				'Pass "allow-test-fixtures" to wp eval-file (e.g. wp eval-file wp-content/plugins/siteconvoy/bin/setup-fixtures.php allow-test-fixtures) ' .
				'or set SITECONVOY_ALLOW_TEST_FIXTURES=1.',
		);
	}

	$allowed_envs = array( 'local', 'development', 'test' );
	if ( ! in_array( $env_type, $allowed_envs, true ) ) {
		return array(
			'allowed' => false,
			'reason'  => sprintf(
				'Refusing to provision synthetic fixtures in "%s" environment. Allowed WP_ENVIRONMENT_TYPE values: %s.',
				$env_type,
				implode( ', ', $allowed_envs )
			),
		);
	}

	if ( ! siteconvoy_is_safe_fixture_host( $home_host ) || ! siteconvoy_is_safe_fixture_host( $site_host ) ) {
		return array(
			'allowed' => false,
			'reason'  => sprintf(
				'Refusing to provision synthetic fixtures on non-local/non-test host (home="%s", siteurl="%s").',
				$home_host,
				$site_host
			),
		);
	}

	return array(
		'allowed' => true,
		'reason'  => '',
	);
}

// Allow unit/integration tests to load only the validation functions without running WP-CLI mutations.
if ( defined( 'SITECONVOY_LOAD_FIXTURE_VALIDATOR_ONLY' ) && true === SITECONVOY_LOAD_FIXTURE_VALIDATOR_ONLY ) {
	return;
}

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "ERROR: This script must be executed via WP-CLI (wp eval-file).\n" );
}

$siteconvoy_cli_args   = isset( $args ) && is_array( $args ) ? $args : array();
$siteconvoy_env_opt_in = '1' === getenv( 'SITECONVOY_ALLOW_TEST_FIXTURES' )
	|| ( defined( 'SITECONVOY_ALLOW_TEST_FIXTURES' ) && true === SITECONVOY_ALLOW_TEST_FIXTURES );
$siteconvoy_env_type   = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
$siteconvoy_home_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$siteconvoy_site_host  = (string) wp_parse_url( site_url(), PHP_URL_HOST );

$siteconvoy_validation = siteconvoy_validate_fixture_context(
	$siteconvoy_cli_args,
	$siteconvoy_env_opt_in,
	$siteconvoy_env_type,
	$siteconvoy_home_host,
	$siteconvoy_site_host
);

if ( ! $siteconvoy_validation['allowed'] ) {
	WP_CLI::error( $siteconvoy_validation['reason'] );
}

global $wpdb;

WP_CLI::log(
	sprintf(
		'Provisioning SiteConvoy synthetic fixtures (env=%s, host=%s, is_multisite=%s, base_prefix=%s)...',
		$siteconvoy_env_type,
		$siteconvoy_home_host,
		is_multisite() ? 'true' : 'false',
		$wpdb->base_prefix
	)
);

// Ensure SiteConvoy plugin is active.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if ( is_multisite() ) {
	if ( ! is_plugin_active_for_network( 'siteconvoy/siteconvoy.php' ) ) {
		activate_plugin( 'siteconvoy/siteconvoy.php', '', true );
		WP_CLI::log( 'Network-activated siteconvoy/siteconvoy.php.' );
	}
} elseif ( ! is_plugin_active( 'siteconvoy/siteconvoy.php' ) ) {
	activate_plugin( 'siteconvoy/siteconvoy.php' );
	WP_CLI::log( 'Activated siteconvoy/siteconvoy.php on standalone site.' );
}

// Create or verify synthetic users (synthetic emails only).
$siteconvoy_author_id = username_exists( 'siteconvoy_author_site2' );
if ( ! $siteconvoy_author_id ) {
	$siteconvoy_author_id = wp_create_user(
		'siteconvoy_author_site2',
		'synthetic-test-password-only',
		'author_site2@siteconvoy-fixture.test'
	);
	WP_CLI::log( sprintf( 'Created synthetic user siteconvoy_author_site2 (ID: %d).', $siteconvoy_author_id ) );
}

$siteconvoy_editor_id = username_exists( 'siteconvoy_editor_shared' );
if ( ! $siteconvoy_editor_id ) {
	$siteconvoy_editor_id = wp_create_user(
		'siteconvoy_editor_shared',
		'synthetic-test-password-only',
		'editor_shared@siteconvoy-fixture.test'
	);
	WP_CLI::log( sprintf( 'Created synthetic user siteconvoy_editor_shared (ID: %d).', $siteconvoy_editor_id ) );
}

// Seed synthetic post on Site ID 1 if not present.
$siteconvoy_site1_posts = get_posts(
	array(
		'name'        => 'siteconvoy-synthetic-main-post',
		'post_type'   => 'post',
		'post_status' => 'publish',
		'numberposts' => 1,
	)
);

if ( empty( $siteconvoy_site1_posts ) ) {
	wp_insert_post(
		array(
			'post_title'   => 'SiteConvoy Synthetic Main Site Post',
			'post_name'    => 'siteconvoy-synthetic-main-post',
			'post_content' => 'Synthetic fixture content for Site ID 1.',
			'post_status'  => 'publish',
			'post_author'  => 1,
		)
	);
	WP_CLI::log( 'Seeded synthetic post on Site ID 1.' );
}

update_option( 'siteconvoy_fixture_seeded', 'site_1_ready', false );

if ( is_multisite() ) {
	$siteconvoy_current_network = get_network();
	$siteconvoy_domain          = $siteconvoy_current_network ? $siteconvoy_current_network->domain : DOMAIN_CURRENT_SITE;
	$siteconvoy_subsite_path    = '/subsite/';

	$siteconvoy_subsite_id = get_blog_id_from_url( $siteconvoy_domain, $siteconvoy_subsite_path );

	if ( ! $siteconvoy_subsite_id ) {
		$siteconvoy_subsite_id = wpmu_create_blog(
			$siteconvoy_domain,
			$siteconvoy_subsite_path,
			'SiteConvoy Synthetic Subsite 2',
			1,
			array( 'public' => 1 ),
			1
		);
		if ( is_wp_error( $siteconvoy_subsite_id ) ) {
			WP_CLI::error( $siteconvoy_subsite_id->get_error_message() );
		}
		WP_CLI::log( sprintf( 'Created synthetic subsite at %s (Site ID: %d).', $siteconvoy_subsite_path, $siteconvoy_subsite_id ) );
	} else {
		WP_CLI::log( sprintf( 'Verified existing synthetic subsite at %s (Site ID: %d).', $siteconvoy_subsite_path, $siteconvoy_subsite_id ) );
	}

	// Assign synthetic roles: author_site2 as Author on Subsite 2 only; editor_shared as Editor on Site 1.
	add_user_to_blog( 1, $siteconvoy_editor_id, 'editor' );
	add_user_to_blog( $siteconvoy_subsite_id, $siteconvoy_author_id, 'author' );

	// Seed synthetic post and option on Subsite 2.
	switch_to_blog( $siteconvoy_subsite_id );

	$siteconvoy_site2_posts = get_posts(
		array(
			'name'        => 'siteconvoy-synthetic-subsite-post',
			'post_type'   => 'post',
			'post_status' => 'publish',
			'numberposts' => 1,
		)
	);

	if ( empty( $siteconvoy_site2_posts ) ) {
		wp_insert_post(
			array(
				'post_title'   => 'SiteConvoy Synthetic Subsite 2 Post',
				'post_name'    => 'siteconvoy-synthetic-subsite-post',
				'post_content' => 'Synthetic fixture content for Subsite 2.',
				'post_status'  => 'publish',
				'post_author'  => $siteconvoy_author_id,
			)
		);
		WP_CLI::log( sprintf( 'Seeded synthetic post on Site ID %d.', $siteconvoy_subsite_id ) );
	}

	update_option( 'siteconvoy_fixture_seeded', 'site_' . $siteconvoy_subsite_id . '_ready', false );
	restore_current_blog();

	WP_CLI::success(
		sprintf(
			'Multisite fixtures ready: Site 1 prefix=%s, Site %d prefix=%s.',
			$wpdb->get_blog_prefix( 1 ),
			$siteconvoy_subsite_id,
			$wpdb->get_blog_prefix( $siteconvoy_subsite_id )
		)
	);
} else {
	WP_CLI::success(
		sprintf(
			'Standalone fixtures ready: Site 1 prefix=%s.',
			$wpdb->get_blog_prefix( 1 )
		)
	);
}
