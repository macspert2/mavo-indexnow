<?php
/**
 * Plugin Name:       IndexNow Auto Submit
 * Plugin URI:        https://example.com/mavo-indexnow
 * Description:        Automatically notifies IndexNow-enabled search engines (Bing, Yandex, Seznam, Naver) when posts are published or updated. Zero-click setup, visible submission log, safe by default.
 * Version:           1.0.0
 * Requires at least: 5.5
 * Requires PHP:      7.2
 * Author:            Mavo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mavo-indexnow
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'MAVO_INDEXNOW_VERSION', '1.0.0' );
define( 'MAVO_INDEXNOW_FILE', __FILE__ );
define( 'MAVO_INDEXNOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAVO_INDEXNOW_BASENAME', plugin_basename( __FILE__ ) );

// Option / meta keys.
define( 'MAVO_INDEXNOW_KEY_OPTION', 'mavo_indexnow_key' );

// The shared IndexNow endpoint. A single submission fans out to all participating engines.
define( 'MAVO_INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow' );

// Cron / action hooks.
define( 'MAVO_INDEXNOW_SUBMIT_HOOK', 'mavo_indexnow_do_submit' );

require_once MAVO_INDEXNOW_DIR . 'includes/class-logger.php';
require_once MAVO_INDEXNOW_DIR . 'includes/class-key-manager.php';
require_once MAVO_INDEXNOW_DIR . 'includes/class-eligibility.php';
require_once MAVO_INDEXNOW_DIR . 'includes/class-submitter.php';
require_once MAVO_INDEXNOW_DIR . 'includes/class-admin-page.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MAVO_INDEXNOW_DIR . 'includes/class-cli.php';
}

/**
 * Bootstraps the plugin once WordPress has loaded.
 */
function mavo_indexnow_init() {
	Mavo_IndexNow_Key_Manager::init();
	Mavo_IndexNow_Eligibility::init();
	Mavo_IndexNow_Submitter::init();

	if ( is_admin() ) {
		Mavo_IndexNow_Admin_Page::init();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::add_command( 'indexnow', 'Mavo_IndexNow_CLI' );
	}
}
add_action( 'plugins_loaded', 'mavo_indexnow_init' );

/**
 * Activation: create the log table, ensure a key exists, register the rewrite
 * rule and flush so the verification file resolves immediately.
 */
function mavo_indexnow_activate() {
	Mavo_IndexNow_Logger::create_table();
	Mavo_IndexNow_Key_Manager::ensure_key();
	Mavo_IndexNow_Key_Manager::add_rewrite_rule();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mavo_indexnow_activate' );

/**
 * Deactivation: clear scheduled submissions and flush rewrite rules.
 * (Options/tables are preserved until uninstall.)
 */
function mavo_indexnow_deactivate() {
	wp_clear_scheduled_hook( MAVO_INDEXNOW_SUBMIT_HOOK );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mavo_indexnow_deactivate' );

/**
 * Multisite: when a new site is created, give it its own key.
 *
 * @param int|WP_Site $site Site ID (legacy) or WP_Site object.
 */
function mavo_indexnow_new_site( $site ) {
	$site_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;

	if ( ! $site_id ) {
		return;
	}

	switch_to_blog( $site_id );
	Mavo_IndexNow_Logger::create_table();
	Mavo_IndexNow_Key_Manager::ensure_key();
	Mavo_IndexNow_Key_Manager::add_rewrite_rule();
	flush_rewrite_rules();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'mavo_indexnow_new_site' ); // Modern multisite hook (WP 5.1+).
