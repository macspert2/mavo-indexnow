<?php
/**
 * Builds the IndexNow request, sends it, logs the real response, and retries
 * failed attempts with exponential backoff.
 *
 * The actual HTTP request runs on a scheduled single event rather than during
 * the publish request. This keeps the editor fast (non-blocking UX) while still
 * capturing the genuine HTTP status/body for the log — the workaround the
 * original plugin lacked.
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_IndexNow_Submitter {

	/**
	 * Backoff delays (seconds) indexed by attempt number (1-based).
	 * Attempt 1 runs almost immediately; 2 and 3 back off.
	 */
	const BACKOFF = array(
		1 => 5,
		2 => 300,   // 5 minutes.
		3 => 1800,  // 30 minutes.
	);

	const MAX_ATTEMPTS = 3;

	/**
	 * Register the async submission handler.
	 */
	public static function init() {
		add_action( MAVO_INDEXNOW_SUBMIT_HOOK, array( __CLASS__, 'run' ), 10, 2 );
	}

	/**
	 * Queues a URL for submission on the next scheduled tick.
	 *
	 * @param string $url     Permalink to submit.
	 * @param int    $attempt Attempt number (internal).
	 */
	public static function queue( $url, $attempt = 1 ) {
		$url = esc_url_raw( $url );

		if ( ! $url ) {
			return;
		}

		$delay = isset( self::BACKOFF[ $attempt ] ) ? self::BACKOFF[ $attempt ] : 5;
		$args  = array( $url, (int) $attempt );

		// Avoid stacking duplicate events for the same url+attempt.
		if ( ! wp_next_scheduled( MAVO_INDEXNOW_SUBMIT_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, MAVO_INDEXNOW_SUBMIT_HOOK, $args );
		}
	}

	/**
	 * Scheduled handler: performs one submission attempt.
	 *
	 * @param string $url     URL to submit.
	 * @param int    $attempt Attempt number.
	 */
	public static function run( $url, $attempt = 1 ) {
		$attempt = max( 1, (int) $attempt );
		$result  = self::submit( $url );

		Mavo_IndexNow_Logger::log(
			array(
				'url'         => $url,
				'http_status' => $result['status'],
				'response'    => $result['message'],
				'success'     => $result['success'] ? 1 : 0,
				'attempt'     => $attempt,
			)
		);

		if ( ! $result['success'] && $attempt < self::MAX_ATTEMPTS ) {
			self::queue( $url, $attempt + 1 );
		}
	}

	/**
	 * Performs a single blocking submission and returns a normalized result.
	 *
	 * @param string $url URL to submit.
	 * @return array{success:bool,status:int,message:string}
	 */
	public static function submit( $url ) {
		$url = esc_url_raw( $url );

		// Validate the final URL — but with NO TLD allow/deny list, so
		// .builders / .website / IDN / non-ASCII slugs all pass.
		if ( ! $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'success' => false,
				'status'  => 0,
				'message' => 'Invalid URL, not submitted: ' . $url,
			);
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$key  = Mavo_IndexNow_Key_Manager::get_key();

		$payload = array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => Mavo_IndexNow_Key_Manager::get_key_location(),
			'urlList'     => array( $url ),
		);

		$response = wp_remote_post(
			MAVO_INDEXNOW_ENDPOINT,
			array(
				'timeout'  => 15,
				'blocking' => true,
				'headers'  => array(
					'Content-Type' => 'application/json; charset=utf-8',
				),
				'body'     => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'status'  => 0,
				'message' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		return array(
			'success' => ( $status >= 200 && $status < 300 ),
			'status'  => $status,
			'message' => self::describe( $status, $body ),
		);
	}

	/**
	 * Turns an IndexNow HTTP status into a readable snippet for the log.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $body   Response body.
	 * @return string
	 */
	protected static function describe( $status, $body ) {
		$known = array(
			200 => 'OK — URL submitted successfully.',
			202 => 'Accepted — URL received; key validation pending.',
			400 => 'Bad request — invalid format.',
			403 => 'Forbidden — key not valid (verification file not found or mismatched).',
			422 => 'Unprocessable — URL does not belong to the host, or key mismatch.',
			429 => 'Too many requests — rate limited.',
		);

		$label = isset( $known[ $status ] ) ? $known[ $status ] : 'HTTP ' . $status;
		$body  = trim( wp_strip_all_tags( (string) $body ) );

		if ( '' !== $body ) {
			$label .= ' | ' . mb_substr( $body, 0, 300 );
		}

		return $label;
	}
}
