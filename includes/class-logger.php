<?php
/**
 * Writes and reads the submission log. Backed by a custom table capped at 200
 * rows so "did it work?" is always answerable from the admin screen.
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_IndexNow_Logger {

	const MAX_ROWS = 200;

	/**
	 * Fully-qualified log table name for the current site.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'mavo_indexnow_log';
	}

	/**
	 * Schema version. Bump whenever create_table()'s CREATE TABLE changes, so
	 * that maybe_upgrade() re-runs dbDelta on sites already installed.
	 */
	const DB_VERSION = 1;

	const DB_VERSION_OPTION = 'mavo_indexnow_db_version';

	/**
	 * Brings an already-installed site's schema up to date.
	 *
	 * create_table() only ever ran from register_activation_hook (and from the
	 * multisite new-site hook), so a table created by an earlier version kept
	 * that version's shape for ever: a column or index added later would have
	 * reached new installs only. dbDelta is idempotent, so the upgrade is
	 * simply "run it again", gated on a stored version so the usual cost is one
	 * option read.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) === self::DB_VERSION ) {
			return;
		}

		self::create_table();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Creates the log table (idempotent, via dbDelta).
	 */
	public static function create_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			submitted_at DATETIME NOT NULL,
			url TEXT NOT NULL,
			http_status SMALLINT NOT NULL DEFAULT 0,
			response TEXT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			attempt TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY submitted_at (submitted_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Inserts one log row and trims the table back to MAX_ROWS.
	 *
	 * @param array $data {
	 *     @type string $url
	 *     @type int    $http_status
	 *     @type string $response
	 *     @type int    $success
	 *     @type int    $attempt
	 * }
	 */
	public static function log( array $data ) {
		global $wpdb;

		$table = self::table_name();

		$wpdb->insert(
			$table,
			array(
				'submitted_at' => current_time( 'mysql' ),
				'url'          => (string) $data['url'],
				'http_status'  => isset( $data['http_status'] ) ? (int) $data['http_status'] : 0,
				'response'     => isset( $data['response'] ) ? (string) $data['response'] : '',
				'success'      => ! empty( $data['success'] ) ? 1 : 0,
				'attempt'      => isset( $data['attempt'] ) ? (int) $data['attempt'] : 1,
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d' )
		);

		self::trim();
	}

	/**
	 * Deletes rows older than the most recent MAX_ROWS entries.
	 */
	protected static function trim() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name cannot be a placeholder.
		$threshold = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
				self::MAX_ROWS
			)
		);
		// phpcs:enable

		if ( $threshold ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $threshold ) );
		}
	}

	/**
	 * Returns the most recent log rows.
	 *
	 * @param int $limit Max rows.
	 * @return array[] Row arrays.
	 */
	public static function get_recent( $limit = self::MAX_ROWS ) {
		global $wpdb;

		$table = self::table_name();
		$limit = (int) $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	/**
	 * Total number of logged attempts.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Drops the log table (used on uninstall).
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
