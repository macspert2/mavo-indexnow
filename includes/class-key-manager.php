<?php
/**
 * Generates, stores and serves the per-site IndexNow key + verification file.
 *
 * The verification file is served virtually via a rewrite rule rather than
 * written to disk, so it works regardless of filesystem permissions and
 * resolves correctly on every multisite subsite.
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_IndexNow_Key_Manager {

	/**
	 * Query var used to route "/{key}.txt" to the virtual file handler.
	 */
	const QUERY_VAR = 'mavo_indexnow_key';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_key_file' ) );
	}

	/**
	 * Returns the site's IndexNow key, generating and persisting one if needed.
	 *
	 * @return string 32-char alphanumeric key.
	 */
	public static function get_key() {
		return self::ensure_key();
	}

	/**
	 * Ensures a key exists for the current site and returns it.
	 *
	 * @return string
	 */
	public static function ensure_key() {
		$key = get_option( MAVO_INDEXNOW_KEY_OPTION );

		if ( ! self::is_valid_key( $key ) ) {
			// Alphanumeric only (no special chars) — valid per the IndexNow spec.
			$key = wp_generate_password( 32, false, false );
			update_option( MAVO_INDEXNOW_KEY_OPTION, $key, false );
		}

		return $key;
	}

	/**
	 * The absolute URL of the verification file, e.g. https://site/ABC...123.txt
	 *
	 * @return string
	 */
	public static function get_key_location() {
		$key = self::ensure_key();

		return home_url( '/' . $key . '.txt' );
	}

	/**
	 * Validate a candidate key: exactly 32 alphanumeric chars.
	 *
	 * @param mixed $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $key );
	}

	/**
	 * Registers the rewrite rule that maps "/{32-char key}.txt" to our handler.
	 * The 32-char exact match avoids colliding with real files (ads.txt,
	 * security.txt, robots.txt, etc.).
	 */
	public static function add_rewrite_rule() {
		add_rewrite_rule(
			'^([A-Za-z0-9]{32})\.txt$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Expose our query var to WP_Query.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Serves the verification file when the requested key matches this site's key.
	 * Requests for a non-matching 32-char .txt fall through to normal WP handling.
	 */
	public static function maybe_serve_key_file() {
		$requested = get_query_var( self::QUERY_VAR );

		if ( '' === $requested || null === $requested ) {
			return;
		}

		if ( ! hash_equals( self::ensure_key(), (string) $requested ) ) {
			return; // Not our key — let WordPress resolve (likely 404).
		}

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( self::ensure_key() );
		exit;
	}
}
