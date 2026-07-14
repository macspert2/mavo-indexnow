<?php
/**
 * Uninstall: remove all plugin data (option + log table).
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up a single site's data.
 */
function mavo_indexnow_uninstall_site() {
	global $wpdb;

	delete_option( 'mavo_indexnow_key' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be parameterized.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mavo_indexnow_log" );

	wp_clear_scheduled_hook( 'mavo_indexnow_do_submit' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		mavo_indexnow_uninstall_site();
		restore_current_blog();
	}
} else {
	mavo_indexnow_uninstall_site();
}
