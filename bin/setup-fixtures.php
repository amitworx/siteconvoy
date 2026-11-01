<?php
/**
 * Deterministic Synthetic Fixture Provisioning Script for SiteConvoy Local Environments.
 *
 * Executed via WP-CLI: wp eval-file wp-content/plugins/siteconvoy/bin/setup-fixtures.php
 *
 * Uses only synthetic test data. Never runs against production.
 *
 * @package SiteConvoy
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "This script must be executed via WP-CLI (wp eval-file).\n" );
}

global $wpdb;

WP_CLI::log( sprintf( 'Provisioning SiteConvoy synthetic fixtures (is_multisite=%s, base_prefix=%s)...', is_multisite() ? 'true' : 'false', $wpdb->base_prefix ) );

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
