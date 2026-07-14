<?php
/**
 * Settings > IndexNow admin screen: shows the key + verification URL, the
 * recent submission log, and per-row / manual resubmit actions.
 *
 * @package Mavo\IndexNow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mavo_IndexNow_Admin_Page {

	const SLUG    = 'mavo-indexnow';
	const CAP     = 'manage_options';
	const NONCE   = 'mavo_indexnow_action';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_mavo_indexnow_resubmit', array( __CLASS__, 'handle_resubmit' ) );
		add_filter( 'plugin_action_links_' . MAVO_INDEXNOW_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add "Settings" link on the Plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'mavo-indexnow' ) . '</a>' );

		return $links;
	}

	/**
	 * Register the settings submenu page.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'IndexNow', 'mavo-indexnow' ),
			__( 'IndexNow', 'mavo-indexnow' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Handles the manual / per-row resubmit form.
	 */
	public static function handle_resubmit() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mavo-indexnow' ) );
		}

		check_admin_referer( self::NONCE );

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( $url ) {
			Mavo_IndexNow_Submitter::queue( $url );
			$notice = 'queued';
		} else {
			$notice = 'invalid';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::SLUG,
					'mavo_indexnow_notice' => $notice,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the admin screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$key          = Mavo_IndexNow_Key_Manager::get_key();
		$key_location = Mavo_IndexNow_Key_Manager::get_key_location();
		$rows         = Mavo_IndexNow_Logger::get_recent( 200 );
		$notice       = isset( $_GET['mavo_indexnow_notice'] ) ? sanitize_key( wp_unslash( $_GET['mavo_indexnow_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'IndexNow', 'mavo-indexnow' ); ?></h1>

			<?php if ( 'queued' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'URL queued for submission. Refresh in a moment to see the result in the log.', 'mavo-indexnow' ); ?></p></div>
			<?php elseif ( 'invalid' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That URL was not valid.', 'mavo-indexnow' ); ?></p></div>
			<?php endif; ?>

			<?php if ( '0' === get_option( 'blog_public' ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Search engine visibility is currently discouraged in Settings > Reading, so no URLs will be submitted until you allow indexing.', 'mavo-indexnow' ); ?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Status', 'mavo-indexnow' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Your IndexNow key', 'mavo-indexnow' ); ?></th>
					<td><code><?php echo esc_html( $key ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Verification file', 'mavo-indexnow' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $key_location ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $key_location ); ?></a>
						<p class="description"><?php esc_html_e( 'This file is served automatically. Opening it should display your key as plain text.', 'mavo-indexnow' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Eligible post types', 'mavo-indexnow' ); ?></th>
					<td><code><?php echo esc_html( implode( ', ', Mavo_IndexNow_Eligibility::eligible_post_types() ) ); ?></code></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Manual submission', 'mavo-indexnow' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="action" value="mavo_indexnow_resubmit" />
				<input type="url" name="url" class="regular-text" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" required />
				<?php submit_button( __( 'Submit URL', 'mavo-indexnow' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent submissions', 'mavo-indexnow' ); ?></h2>
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No submissions logged yet. Publish or update a post to see activity here.', 'mavo-indexnow' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'mavo-indexnow' ); ?></th>
							<th><?php esc_html_e( 'URL', 'mavo-indexnow' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mavo-indexnow' ); ?></th>
							<th><?php esc_html_e( 'Response', 'mavo-indexnow' ); ?></th>
							<th><?php esc_html_e( 'Try', 'mavo-indexnow' ); ?></th>
							<th><?php esc_html_e( 'Action', 'mavo-indexnow' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $row['submitted_at'] ) ); ?></td>
								<td><a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row['url'] ); ?></a></td>
								<td>
									<?php if ( $row['success'] ) : ?>
										<span style="color:#008a20;">&#10004; <?php echo esc_html( $row['http_status'] ); ?></span>
									<?php else : ?>
										<span style="color:#d63638;">&#10008; <?php echo esc_html( $row['http_status'] ? $row['http_status'] : 'ERR' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $row['response'] ); ?></td>
								<td><?php echo esc_html( $row['attempt'] ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<?php wp_nonce_field( self::NONCE ); ?>
										<input type="hidden" name="action" value="mavo_indexnow_resubmit" />
										<input type="hidden" name="url" value="<?php echo esc_attr( $row['url'] ); ?>" />
										<?php submit_button( __( 'Resubmit', 'mavo-indexnow' ), 'small', 'submit', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
