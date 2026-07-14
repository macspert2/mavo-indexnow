<?php
/**
 * Decides whether a post should be submitted to IndexNow and hooks the
 * publish/update lifecycle. Safe by default: never submits noindex, private,
 * password-protected or non-public content.
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_IndexNow_Eligibility {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
	}

	/**
	 * Fires on every status change. Queues a submission when a post becomes (or
	 * stays) published and passes eligibility.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post object.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return; // Only notify for live URLs (covers new publishes and edits to published posts).
		}

		if ( ! self::is_eligible( $post ) ) {
			return;
		}

		$url = get_permalink( $post );

		if ( $url ) {
			Mavo_IndexNow_Submitter::queue( $url );
		}
	}

	/**
	 * The default set of post types the plugin will submit. Filterable.
	 *
	 * @return string[]
	 */
	public static function eligible_post_types() {
		/**
		 * Filters the post types eligible for IndexNow submission.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return (array) apply_filters( 'indexnow_eligible_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Determines whether a given post should be submitted.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_eligible( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		// Ignore revisions and autosaves.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		// Post type must be in the allowed, publicly-queryable set.
		if ( ! in_array( $post->post_type, self::eligible_post_types(), true ) ) {
			return false;
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ! $post_type_obj->public ) {
			return false;
		}

		// Must be live and openly visible.
		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( 'private' === get_post_status( $post ) ) {
			return false;
		}

		if ( ! empty( $post->post_password ) ) {
			return false;
		}

		// Respect the site-wide "Discourage search engines" setting.
		if ( '0' === get_option( 'blog_public' ) ) {
			return false;
		}

		// Respect per-post noindex from Yoast SEO / Rank Math when present.
		if ( self::is_noindexed( $post->ID ) ) {
			return false;
		}

		/**
		 * Final say on eligibility.
		 *
		 * @param bool    $eligible Whether the post is eligible (true here).
		 * @param WP_Post $post     The post.
		 */
		return (bool) apply_filters( 'indexnow_post_is_eligible', true, $post );
	}

	/**
	 * Soft check for a per-post noindex directive from popular SEO plugins.
	 * Only consulted when the relevant meta exists — no hard dependency.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if the post is marked noindex.
	 */
	protected static function is_noindexed( $post_id ) {
		// Yoast SEO: '1' means noindex.
		$yoast = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === (string) $yoast ) {
			return true;
		}

		// Rank Math: stored as an array of robots directives.
		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return true;
		}

		return false;
	}
}
