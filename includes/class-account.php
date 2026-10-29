<?php
/**
 * Customer side: an "Archived orders" tab in My Account, read-only.
 * Customers keep seeing their old orders after they are archived.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

/**
 * My Account endpoint for archived orders.
 */
class Nextial_OA_Account {

	const ENDPOINT    = 'archived-orders';
	const OPT_ENABLED = 'nextial_oa_myaccount';
	const OPT_REWRITE = 'nextial_oa_rewrite';
	const REWRITE_VER = '1';

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ), 10, 2 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
	}

	/** On by default: customers should not lose sight of their orders. */
	public static function enabled() {
		return 'no' !== get_option( self::OPT_ENABLED, 'yes' );
	}

	public static function set_enabled( $on ) {
		update_option( self::OPT_ENABLED, $on ? 'yes' : 'no', true );
		delete_option( self::OPT_REWRITE ); // Rules change: flush on the next request.
	}

	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/** WooCommerce registers the endpoint; flush rewrite rules once so the URL works. */
	public static function maybe_flush() {
		if ( get_option( self::OPT_REWRITE ) !== self::REWRITE_VER ) {
			flush_rewrite_rules( false );
			update_option( self::OPT_REWRITE, self::REWRITE_VER, true );
		}
	}

	/** Tab after "Orders", only for customers who have archived orders. */
	public static function menu( $items ) {
		if ( ! is_user_logged_in() || 0 === self::count_for( get_current_user_id() ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = __( 'Archived orders', 'nextial-order-archive' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = __( 'Archived orders', 'nextial-order-archive' );
		}
		return $out;
	}

	public static function title( $title, $endpoint = '' ) {
		global $wp;
		$id = isset( $wp->query_vars[ self::ENDPOINT ] ) ? absint( $wp->query_vars[ self::ENDPOINT ] ) : 0;
		/* translators: %s: order number */
		return $id ? sprintf( __( 'Archived order #%s', 'nextial-order-archive' ), $id ) : __( 'Archived orders', 'nextial-order-archive' );
	}

	/* ------------------------------------------------------------------ */
	/* Data (archive tables only, always filtered by customer)             */
	/* ------------------------------------------------------------------ */

	private static function t( $live ) {
		global $wpdb;
		return Nextial_OA_Archiver::archive_table( $wpdb->prefix . $live );
	}

	private static function archive_ready() {
		global $wpdb;
		$o = self::t( 'wc_orders' );
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $o ) ) === $o;
	}

	public static function count_for( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! self::archive_ready() ) {
			return 0;
		}
		$o = self::t( 'wc_orders' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$o}` WHERE type = 'shop_order' AND customer_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One page of a customer's archived orders, newest first.
	 *
	 * @return array[]
	 */
	public static function orders_for( $user_id, $page = 1, $per_page = 20 ) {
		global $wpdb;
		if ( ! self::archive_ready() ) {
			return array();
		}
		$o = self::t( 'wc_orders' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, currency, total_amount, date_created_gmt FROM `{$o}` WHERE type = 'shop_order' AND customer_id = %d ORDER BY date_created_gmt DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $user_id,
				(int) $per_page,
				max( 0, ( (int) $page - 1 ) * (int) $per_page )
			),
			ARRAY_A
		);
	}

	/**
	 * Full archived order, or null if it is not this customer's.
	 *
	 * @return array|null
	 */
	public static function order_for( $user_id, $order_id ) {
		global $wpdb;
		$user_id  = (int) $user_id;
		$order_id = (int) $order_id;
		if ( $user_id <= 0 || $order_id <= 0 || ! self::archive_ready() ) {
			return null;
		}
		$o     = self::t( 'wc_orders' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$o}` WHERE id = %d AND type = 'shop_order' AND customer_id = %d", $order_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $order ) {
			return null;
		}
		$items_t = self::t( 'woocommerce_order_items' );
		$meta_t  = self::t( 'woocommerce_order_itemmeta' );
		$addr_t  = self::t( 'wc_order_addresses' );
		$op_t    = self::t( 'wc_order_operational_data' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT order_item_id, order_item_name, order_item_type FROM `{$items_t}` WHERE order_id = %d ORDER BY order_item_id", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items   = array();
		$fees    = array();
		foreach ( $rows as $row ) {
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM `{$meta_t}` WHERE order_item_id = %d AND meta_key IN ('_qty','_line_total','_line_tax','cost','total_tax')", (int) $row['order_item_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$m    = wp_list_pluck( $meta, 'meta_value', 'meta_key' );
			if ( 'line_item' === $row['order_item_type'] ) {
				$items[] = array(
					'name'  => $row['order_item_name'],
					'qty'   => (float) ( $m['_qty'] ?? 0 ),
					'total' => (float) ( $m['_line_total'] ?? 0 ),
				);
			} elseif ( in_array( $row['order_item_type'], array( 'shipping', 'fee' ), true ) ) {
				$fees[] = array(
					'name'  => $row['order_item_name'],
					'total' => (float) ( $m['cost'] ?? ( $m['_line_total'] ?? 0 ) ),
				);
			}
		}
		$addresses = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$addr_t}` WHERE order_id = %d", $order_id ), ARRAY_A ) as $a ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$addresses[ $a['address_type'] ] = $a;
		}
		$refunded = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM `{$o}` WHERE type = 'shop_order_refund' AND parent_order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$op       = $wpdb->get_row( $wpdb->prepare( "SELECT discount_total_amount FROM `{$op_t}` WHERE order_id = %d", $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'order'     => $order,
			'items'     => $items,
			'fees'      => $fees,
			'addresses' => $addresses,
			'refunded'  => abs( $refunded ),
			'discount'  => (float) ( $op['discount_total_amount'] ?? 0 ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Output                                                              */
	/* ------------------------------------------------------------------ */

	private static function status_label( $status ) {
		$labels = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		return $labels[ $status ] ?? ucfirst( str_replace( 'wc-', '', (string) $status ) );
	}

	private static function date( $gmt ) {
		return $gmt ? get_date_from_gmt( $gmt, get_option( 'date_format' ) ) : '';
	}

	public static function render( $value = '' ) {
		$user = get_current_user_id();
		$id   = absint( $value );
		if ( $id ) {
			self::render_order( $user, $id );
			return;
		}
		$page   = isset( $_GET['nxoa_page'] ) ? max( 1, absint( $_GET['nxoa_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$total  = self::count_for( $user );
		$orders = self::orders_for( $user, $page, 20 );
		if ( ! $orders ) {
			echo '<p>' . esc_html__( 'You have no archived orders.', 'nextial-order-archive' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Older orders are kept here. They are read-only; contact us if you need anything about one of them.', 'nextial-order-archive' ) . '</p>';
		?>
		<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders account-orders-table nxoa-archived-orders">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Order', 'nextial-order-archive' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'nextial-order-archive' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'nextial-order-archive' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Total', 'nextial-order-archive' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'nextial-order-archive' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $orders as $o ) : ?>
				<tr>
					<th scope="row" data-title="<?php esc_attr_e( 'Order', 'nextial-order-archive' ); ?>">#<?php echo esc_html( (string) $o['id'] ); ?></th>
					<td data-title="<?php esc_attr_e( 'Date', 'nextial-order-archive' ); ?>"><?php echo esc_html( self::date( $o['date_created_gmt'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Status', 'nextial-order-archive' ); ?>"><?php echo esc_html( self::status_label( $o['status'] ) ); ?></td>
					<td data-title="<?php esc_attr_e( 'Total', 'nextial-order-archive' ); ?>"><?php echo wp_kses_post( wc_price( (float) $o['total_amount'], array( 'currency' => $o['currency'] ) ) ); ?></td>
					<td><a class="woocommerce-button button view" href="<?php echo esc_url( wc_get_endpoint_url( self::ENDPOINT, (string) $o['id'], wc_get_page_permalink( 'myaccount' ) ) ); ?>"><?php esc_html_e( 'View', 'nextial-order-archive' ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / 20 );
		if ( $pages > 1 ) {
			$base = wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) );
			echo '<div class="woocommerce-pagination">';
			if ( $page > 1 ) {
				echo '<a class="woocommerce-button button" href="' . esc_url( add_query_arg( 'nxoa_page', $page - 1, $base ) ) . '">' . esc_html__( 'Previous', 'nextial-order-archive' ) . '</a> ';
			}
			if ( $page < $pages ) {
				echo '<a class="woocommerce-button button" href="' . esc_url( add_query_arg( 'nxoa_page', $page + 1, $base ) ) . '">' . esc_html__( 'Next', 'nextial-order-archive' ) . '</a>';
			}
			echo '</div>';
		}
	}

	private static function render_order( $user, $id ) {
		$d = self::order_for( $user, $id );
		if ( ! $d ) {
			echo '<p>' . esc_html__( 'Order not found.', 'nextial-order-archive' ) . '</p>';
			return;
		}
		$o   = $d['order'];
		$cur = array( 'currency' => $o['currency'] );
		echo '<p>' . sprintf(
			/* translators: 1: order number, 2: date, 3: status */
			esc_html__( 'Order #%1$s was placed on %2$s and is %3$s.', 'nextial-order-archive' ),
			'<mark>' . esc_html( (string) $o['id'] ) . '</mark>',
			'<mark>' . esc_html( self::date( $o['date_created_gmt'] ) ) . '</mark>',
			'<mark>' . esc_html( self::status_label( $o['status'] ) ) . '</mark>'
		) . '</p>';
		?>
		<table class="woocommerce-table woocommerce-table--order-details shop_table order_details">
			<thead><tr><th><?php esc_html_e( 'Product', 'nextial-order-archive' ); ?></th><th><?php esc_html_e( 'Total', 'nextial-order-archive' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $d['items'] as $it ) : ?>
				<tr><td><?php echo esc_html( $it['name'] ); ?> <strong>&times;&nbsp;<?php echo esc_html( wc_stock_amount( $it['qty'] ) ); ?></strong></td><td><?php echo wp_kses_post( wc_price( $it['total'], $cur ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
			<?php if ( $d['discount'] > 0 ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Discount', 'nextial-order-archive' ); ?></th><td>-<?php echo wp_kses_post( wc_price( $d['discount'], $cur ) ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $d['fees'] as $f ) : ?>
				<tr><th scope="row"><?php echo esc_html( $f['name'] ); ?></th><td><?php echo wp_kses_post( wc_price( $f['total'], $cur ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( (float) $o['tax_amount'] > 0 ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Tax', 'nextial-order-archive' ); ?></th><td><?php echo wp_kses_post( wc_price( (float) $o['tax_amount'], $cur ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( ! empty( $o['payment_method_title'] ) ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Payment method', 'nextial-order-archive' ); ?></th><td><?php echo esc_html( $o['payment_method_title'] ); ?></td></tr>
			<?php endif; ?>
				<tr><th scope="row"><?php esc_html_e( 'Total', 'nextial-order-archive' ); ?></th><td><?php echo wp_kses_post( wc_price( (float) $o['total_amount'], $cur ) ); ?></td></tr>
			<?php if ( $d['refunded'] > 0 ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Refunded', 'nextial-order-archive' ); ?></th><td>-<?php echo wp_kses_post( wc_price( $d['refunded'], $cur ) ); ?></td></tr>
			<?php endif; ?>
			</tfoot>
		</table>
		<?php
		foreach ( array( 'billing' => __( 'Billing address', 'nextial-order-archive' ), 'shipping' => __( 'Shipping address', 'nextial-order-archive' ) ) as $type => $label ) {
			if ( empty( $d['addresses'][ $type ] ) ) {
				continue;
			}
			$a    = $d['addresses'][ $type ];
			$html = WC()->countries->get_formatted_address(
				array(
					'first_name' => $a['first_name'],
					'last_name'  => $a['last_name'],
					'company'    => $a['company'],
					'address_1'  => $a['address_1'],
					'address_2'  => $a['address_2'],
					'city'       => $a['city'],
					'state'      => $a['state'],
					'postcode'   => $a['postcode'],
					'country'    => $a['country'],
				)
			);
			echo '<h2>' . esc_html( $label ) . '</h2><address>' . wp_kses_post( $html ) . '</address>';
		}
		echo '<p><a class="woocommerce-button button" href="' . esc_url( wc_get_endpoint_url( self::ENDPOINT, '', wc_get_page_permalink( 'myaccount' ) ) ) . '">' . esc_html__( 'Back to archived orders', 'nextial-order-archive' ) . '</a></p>';
	}
}
