<?php
/**
 * WooCommerce → Order Archive screen.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OA_Admin {

	const SLUG       = 'nextial-order-archive';
	const NOTICE_KEY = 'nextial_oa_notice_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_nextial_oa_save', array( __CLASS__, 'action_save' ) );
		add_action( 'admin_post_nextial_oa_start', array( __CLASS__, 'action_start' ) );
		add_action( 'admin_post_nextial_oa_stop', array( __CLASS__, 'action_stop' ) );
		add_action( 'admin_post_nextial_oa_restore', array( __CLASS__, 'action_restore' ) );
		add_action( 'admin_notices', array( __CLASS__, 'orders_screen_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NEXTIAL_OA_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function cap() {
		return 'manage_woocommerce';
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Order Archive', 'nextial-order-archive' ), __( 'Order Archive', 'nextial-order-archive' ), self::cap(), self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function page_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'nextial-order-archive' ) . '</a>' );
		return $links;
	}

	/** On the WooCommerce Orders screen, say where archived orders are. */
	public static function orders_screen_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( self::cap() ) || false === strpos( (string) $screen->id, 'wc-orders' ) ) {
			return;
		}
		$n = Nextial_OA_Archiver::archived_count();
		if ( $n < 1 ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html(
			sprintf(
				/* translators: %d: number of archived orders */
				_n( '%d older order is in the archive and not listed here.', '%d older orders are in the archive and not listed here.', $n, 'nextial-order-archive' ),
				$n
			)
		) . ' <a href="' . esc_url( self::page_url() ) . '#archived">' . esc_html__( 'Search the archive', 'nextial-order-archive' ) . '</a></p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	private static function guard( $action ) {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nextial-order-archive' ) );
		}
		check_admin_referer( $action );
	}

	private static function done( $type, $text, array $args = array() ) {
		set_transient(
			self::NOTICE_KEY . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
			),
			300
		);
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	public static function action_save() {
		self::guard( 'nextial_oa_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in guard().
		$statuses = isset( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['statuses'] ) ) : array();
		$months   = isset( $_POST['months'] ) ? (int) $_POST['months'] : 12;
		$batch    = isset( $_POST['batch_size'] ) ? (int) $_POST['batch_size'] : 50;
		$auto     = ! empty( $_POST['auto'] );
		$preview  = ! empty( $_POST['preview'] );
		// phpcs:enable
		Nextial_OA_Runner::save_rule(
			array(
				'statuses'        => $statuses,
				'older_than_days' => (int) round( $months * 30.4 ),
				'batch_size'      => $batch,
				'auto'            => $auto,
			)
		);
		if ( $preview ) {
			set_transient( 'nextial_oa_preview_' . get_current_user_id(), Nextial_OA_Archiver::preview( Nextial_OA_Runner::rule() ), 600 );
			self::done( 'info', __( 'Rule saved. Preview below: nothing was moved.', 'nextial-order-archive' ) );
		}
		self::done( 'success', __( 'Rule saved.', 'nextial-order-archive' ) );
	}

	public static function action_start() {
		self::guard( 'nextial_oa_start' );
		if ( ! Nextial_OA_Runner::start() ) {
			self::done( 'error', __( 'Could not start: check the requirements at the top of this page.', 'nextial-order-archive' ) );
		}
		self::done( 'success', __( 'Archiving started in the background, in small batches. Reload this page to follow the progress.', 'nextial-order-archive' ) );
	}

	public static function action_stop() {
		self::guard( 'nextial_oa_stop' );
		Nextial_OA_Runner::stop();
		self::done( 'warning', __( 'Stopped. Orders already archived stay in the archive; nothing is half-moved.', 'nextial-order-archive' ) );
	}

	public static function action_restore() {
		self::guard( 'nextial_oa_restore' );
		$id  = isset( $_POST['order'] ) ? (int) $_POST['order'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$res = Nextial_OA_Archiver::restore( array( $id ) );
		if ( $res['ok'] ) {
			/* translators: %d: order ID */
			self::done( 'success', sprintf( __( 'Order #%d restored. It is back in WooCommerce → Orders.', 'nextial-order-archive' ), $id ) );
		}
		/* translators: %s: error */
		self::done( 'error', sprintf( __( 'Nothing was restored: %s', 'nextial-order-archive' ), $res['error'] ) );
	}

	private static function post_button( $action, $label, array $fields = array(), $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form> ';
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$uid     = get_current_user_id();
		$notice  = get_transient( self::NOTICE_KEY . $uid );
		$preview = get_transient( 'nextial_oa_preview_' . $uid );
		delete_transient( self::NOTICE_KEY . $uid );
		delete_transient( 'nextial_oa_preview_' . $uid );
		$env    = Nextial_OA_Archiver::check_environment();
		$rule   = Nextial_OA_Runner::rule();
		$run    = Nextial_OA_Runner::run();
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$labels = array(
			'completed'      => __( 'Completed', 'nextial-order-archive' ),
			'refunded'       => __( 'Refunded', 'nextial-order-archive' ),
			'cancelled'      => __( 'Cancelled', 'nextial-order-archive' ),
			'failed'         => __( 'Failed', 'nextial-order-archive' ),
			'pending'        => __( 'Pending payment', 'nextial-order-archive' ),
			'checkout-draft' => __( 'Checkout draft', 'nextial-order-archive' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		?>
		<div class="wrap nextial-oa">
			<h1><?php esc_html_e( 'Order Archive', 'nextial-order-archive' ); ?> <span style="font-size:13px;color:#646970">Nextial · v<?php echo esc_html( NEXTIAL_OA_VERSION ); ?></span></h1>
			<p><?php esc_html_e( 'Moves old, finished orders out of the live order tables into an archive, so the Orders screen and searches stay fast. Archived orders keep their ID, can be searched below and restored in one click. Reports (Analytics) keep their history.', 'nextial-order-archive' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( true !== $env ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $env ); ?></p></div>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %d: archived orders */
						esc_html__( 'Orders in the archive: %d.', 'nextial-order-archive' ),
						(int) Nextial_OA_Archiver::archived_count()
					);
					?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( '1. Rule', 'nextial-order-archive' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nextial_oa_save">
				<?php wp_nonce_field( 'nextial_oa_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Order statuses', 'nextial-order-archive' ); ?></th>
						<td>
							<?php foreach ( $labels as $k => $label ) : ?>
								<label style="margin-right:14px"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $rule['statuses'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Processing and on-hold orders are never archived. Orders linked to a subscription, or with downloads the customer can still use, stay live.', 'nextial-order-archive' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="nxoa-months"><?php esc_html_e( 'Older than', 'nextial-order-archive' ); ?></label></th>
						<td><input id="nxoa-months" type="number" min="1" max="120" name="months" value="<?php echo esc_attr( (string) max( 1, (int) round( $rule['older_than_days'] / 30.4 ) ) ); ?>" class="small-text"> <?php esc_html_e( 'months', 'nextial-order-archive' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="nxoa-batch"><?php esc_html_e( 'Batch size', 'nextial-order-archive' ); ?></label></th>
						<td><input id="nxoa-batch" type="number" min="10" max="200" name="batch_size" value="<?php echo esc_attr( (string) $rule['batch_size'] ); ?>" class="small-text"> <?php esc_html_e( 'orders per step (lower it on slow hosting)', 'nextial-order-archive' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic', 'nextial-order-archive' ); ?></th>
						<td><label><input type="checkbox" name="auto" value="1" <?php checked( $rule['auto'] ); ?>> <?php esc_html_e( 'Run this rule every night at 3:00', 'nextial-order-archive' ); ?></label></td>
					</tr>
				</table>
				<p>
					<button type="submit" name="preview" value="1" class="button button-secondary"><?php esc_html_e( 'Save and preview (nothing is moved)', 'nextial-order-archive' ); ?></button>
					<button type="submit" class="button"><?php esc_html_e( 'Save', 'nextial-order-archive' ); ?></button>
				</p>
			</form>

			<?php if ( is_array( $preview ) ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: 1: matching, 2: archivable, 3: subscription, 4: downloads */
						esc_html__( 'Matching orders: %1$d. Would be archived: %2$d. Kept live because of a subscription: %3$d, because of active downloads: %4$d.', 'nextial-order-archive' ),
						(int) $preview['total'],
						(int) $preview['archivable'],
						(int) ( $preview['excluded']['subscription'] ?? 0 ),
						(int) ( $preview['excluded']['downloads'] ?? 0 )
					);
					if ( $preview['oldest'] ) {
						echo ' ';
						/* translators: 1: date, 2: date */
						printf( esc_html__( 'From %1$s to %2$s.', 'nextial-order-archive' ), esc_html( get_date_from_gmt( $preview['oldest'], get_option( 'date_format' ) ) ), esc_html( get_date_from_gmt( $preview['newest'], get_option( 'date_format' ) ) ) );
					}
					?>
				</p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( '2. Archive', 'nextial-order-archive' ); ?></h2>
			<p><?php esc_html_e( 'Make a database backup before the first run. Each step moves a small batch inside a database transaction and checks every row count before removing anything from the live tables.', 'nextial-order-archive' ); ?></p>
			<?php if ( $run && 'running' === $run['status'] ) : ?>
				<p><strong>
					<?php
					/* translators: 1: orders moved, 2: start time */
					printf( esc_html__( 'Running: %1$d orders archived so far (started %2$s).', 'nextial-order-archive' ), (int) $run['moved'], esc_html( wp_date( $fmt, (int) $run['started'] ) ) );
					?>
				</strong></p>
				<p><?php self::post_button( 'nextial_oa_stop', __( 'Stop', 'nextial-order-archive' ) ); ?></p>
			<?php else : ?>
				<?php if ( $run ) : ?>
					<p>
						<?php
						/* translators: 1: status, 2: count, 3: date */
						printf( esc_html__( 'Last run: %1$s, %2$d orders archived (started %3$s).', 'nextial-order-archive' ), esc_html( $run['status'] ), (int) $run['moved'], esc_html( wp_date( $fmt, (int) $run['started'] ) ) );
						if ( ! empty( $run['error'] ) ) {
							echo ' <span style="color:#b32d2e">' . esc_html( $run['error'] ) . '</span>';
						}
						?>
					</p>
				<?php endif; ?>
				<p><?php self::post_button( 'nextial_oa_start', __( 'Archive matching orders now', 'nextial-order-archive' ), array(), true === $env ? 'button button-primary' : 'button button-primary disabled' ); ?></p>
			<?php endif; ?>

			<h2 id="archived"><?php esc_html_e( '3. Archived orders', 'nextial-order-archive' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Order number, e-mail or name', 'nextial-order-archive' ); ?>" class="regular-text">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'nextial-order-archive' ); ?></button>
			</form>
			<?php $rows = Nextial_OA_Archiver::search_archived( $search, 50 ); ?>
			<?php if ( empty( $rows ) ) : ?>
				<p><em><?php echo '' === $search ? esc_html__( 'The archive is empty.', 'nextial-order-archive' ) : esc_html__( 'No archived order matches.', 'nextial-order-archive' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px;margin-top:10px">
					<thead><tr><th><?php esc_html_e( 'Order', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Date', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Customer', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Status', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Total', 'nextial-order-archive' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td>#<?php echo esc_html( $r['id'] ); ?></td>
							<td><?php echo esc_html( $r['date_created_gmt'] ? get_date_from_gmt( $r['date_created_gmt'], get_option( 'date_format' ) ) : '' ); ?></td>
							<td><?php echo esc_html( trim( $r['first_name'] . ' ' . $r['last_name'] ) ); ?><br><span style="color:#646970"><?php echo esc_html( $r['billing_email'] ); ?></span></td>
							<td><?php echo esc_html( preg_replace( '/^wc-/', '', (string) $r['status'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) $r['total_amount'], 2 ) . ' ' . $r['currency'] ); ?></td>
							<td><?php self::post_button( 'nextial_oa_restore', __( 'Restore', 'nextial-order-archive' ), array( 'order' => $r['id'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( '4. Log', 'nextial-order-archive' ); ?></h2>
			<?php $log = array_reverse( Nextial_OA_Log::all() ); ?>
			<?php if ( empty( $log ) ) : ?>
				<p><em><?php esc_html_e( 'Nothing yet.', 'nextial-order-archive' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr><th><?php esc_html_e( 'When', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Action', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Orders', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Detail', 'nextial-order-archive' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 20 ) as $l ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( $fmt, (int) $l['at'] ) ); ?></td>
							<td><?php echo esc_html( $l['action'] ); ?></td>
							<td><?php echo esc_html( (string) $l['count'] ); ?></td>
							<td><?php echo esc_html( $l['error'] ? $l['error'] : '✓' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
