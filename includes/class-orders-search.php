<?php
/**
 * Free since 0.3.0: a search on WooCommerce → Orders also finds archived
 * orders, with one-click "Restore to Orders" (staff never need to know where
 * an order is). Works with HPOS (wc-orders) and the classic screen.
 *
 * Add-ons can add their own rows (e.g. cold storage) with the filter
 * `nextial_oa_orders_search_rows` and get the order back first with
 * `nextial_oa_restore_live_prepare`.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OA_Orders_Search {

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 19 );
		add_action( 'admin_post_nextial_oa_restore_live', array( __CLASS__, 'action_restore_live' ) );
	}

	/** True on WooCommerce → Orders (HPOS or classic). */
	public static function on_orders_screen( $screen ) {
		return $screen && ( false !== strpos( (string) $screen->id, 'wc-orders' ) || 'edit-shop_order' === $screen->id );
	}

	/**
	 * Rows to show for a search term: archived orders, plus what add-ons add.
	 *
	 * @return array<int, array{id:int, date:string, customer:string, total:string, currency:string, where:string, from:string}>
	 */
	public static function rows( $term, $limit = 10 ) {
		$rows = array();
		foreach ( Nextial_OA_Archiver::search_archived( $term, $limit ) as $r ) {
			$rows[] = array(
				'id'       => (int) $r['id'],
				'date'     => (string) $r['date_created_gmt'],
				'customer' => trim( trim( $r['first_name'] . ' ' . $r['last_name'] ) . ' ' . $r['billing_email'] ),
				'total'    => (string) $r['total_amount'],
				'currency' => (string) $r['currency'],
				'where'    => __( 'archive', 'nextial-order-archive' ),
				'from'     => 'warm',
			);
		}
		return (array) apply_filters( 'nextial_oa_orders_search_rows', $rows, $term, $limit );
	}

	public static function render() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! self::on_orders_screen( $screen ) || ! current_user_can( Nextial_OA_Admin::cap() ) ) {
			return;
		}
		// Order Archive Pro up to 0.7.0 shows its own box (archive + cold storage): do not show it twice.
		if ( class_exists( 'Nextial_OAP_Extras' ) && has_action( 'admin_notices', array( 'Nextial_OAP_Extras', 'orders_search_results' ) ) ) {
			return;
		}
		$term = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		if ( '' === $term ) {
			return;
		}
		$rows = self::rows( $term, 10 );
		if ( ! $rows ) {
			return;
		}
		echo '<div class="notice notice-info nextial-oa-orders-search"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: search term */
				__( 'Also found in Order Archive for "%s":', 'nextial-order-archive' ),
				$term
			)
		) . '</strong></p><table class="widefat striped" style="max-width:900px;margin-bottom:8px"><tbody>';
		foreach ( $rows as $r ) {
			$total = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $r['total'], 2 ) : $r['total'];
			echo '<tr><td>#' . esc_html( (string) $r['id'] ) . '</td><td>' . esc_html( $r['date'] ) . '</td><td>' . esc_html( $r['customer'] ) . '</td><td>' . esc_html( $total . ' ' . $r['currency'] ) . '</td><td>' . esc_html( $r['where'] ) . '</td><td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="nextial_oa_restore_live"><input type="hidden" name="order_id" value="' . esc_attr( (string) $r['id'] ) . '"><input type="hidden" name="from" value="' . esc_attr( $r['from'] ) . '">';
			wp_nonce_field( 'nextial_oa_restore_live' );
			echo '<button class="button button-small">' . esc_html__( 'Restore to Orders', 'nextial-order-archive' ) . '</button></form></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function action_restore_live() {
		if ( ! current_user_can( Nextial_OA_Admin::cap() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'nextial-order-archive' ) );
		}
		check_admin_referer( 'nextial_oa_restore_live' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$from = isset( $_POST['from'] ) ? sanitize_key( wp_unslash( $_POST['from'] ) ) : 'warm';
		// phpcs:enable
		$err = (string) apply_filters( 'nextial_oa_restore_live_prepare', '', $id, $from );
		if ( '' === $err ) {
			$r = Nextial_OA_Archiver::restore( array( $id ) );
			if ( ! $r['ok'] ) {
				$err = $r['error'];
			}
		}
		if ( '' !== $err ) {
			wp_die( esc_html( $err ), '', array( 'back_link' => true ) );
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
		wp_safe_redirect( $order ? $order->get_edit_order_url() : admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}
}
