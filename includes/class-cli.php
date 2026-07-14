<?php
/**
 * WP-CLI command: `wp indexnow submit <url>` for manual/debug submission.
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Mavo_IndexNow_CLI {

	/**
	 * Submits a URL to IndexNow immediately (blocking) and prints the result.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The absolute URL to submit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexnow submit https://example.com/hello-world/
	 *
	 * @param array $args Positional args.
	 */
	public function submit( $args ) {
		$url = isset( $args[0] ) ? esc_url_raw( $args[0] ) : '';

		if ( ! $url ) {
			WP_CLI::error( 'Please provide a valid URL.' );
		}

		$result = Mavo_IndexNow_Submitter::submit( $url );

		Mavo_IndexNow_Logger::log(
			array(
				'url'         => $url,
				'http_status' => $result['status'],
				'response'    => $result['message'],
				'success'     => $result['success'] ? 1 : 0,
				'attempt'     => 1,
			)
		);

		if ( $result['success'] ) {
			WP_CLI::success( sprintf( '[%d] %s', $result['status'], $result['message'] ) );
		} else {
			WP_CLI::error( sprintf( '[%d] %s', $result['status'], $result['message'] ) );
		}
	}

	/**
	 * Prints this site's IndexNow key and verification file URL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp indexnow key
	 */
	public function key() {
		WP_CLI::log( 'Key:      ' . Mavo_IndexNow_Key_Manager::get_key() );
		WP_CLI::log( 'Location: ' . Mavo_IndexNow_Key_Manager::get_key_location() );
	}
}
