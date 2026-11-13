<?php
/**
 * WordPress privacy tools (Tools → Export/Erase Personal Data) for orders
 * that live in the archive tables.
 *
 * WooCommerce only sees live orders, so without this an access or erasure
 * request would silently skip every archived order.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OA_Privacy {

	/** Orders handled per page of a request. */
	const PER_PAGE = 10;

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ), 12 );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ), 12 );
	}

	public static function register_exporter( $exporters ) {
		$exporters['nextial-order-archive'] = array(
			'exporter_friendly_name' => __( 'Archived WooCommerce orders', 'nextial-order-archive' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['nextial-order-archive'] = array(
			'eraser_friendly_name' => __( 'Archived WooCommerce orders', 'nextial-order-archive' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Archived orders of one person: matched by billing e-mail, and by user
	 * ID when the e-mail belongs to an account.
	 *
	 * @param string $email_address E-mail from the request.
	 * @param int    $page          1-based page.
	 * @return int[] Order IDs.
	 */
	private static function order_ids( $email_address, $page ) {
		global $wpdb;
		$o = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'wc_orders' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $o ) ) !== $o ) {
			return array();
		}
		$a      = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'wc_order_addresses' );
		$user   = get_user_by( 'email', $email_address );
		$offset = ( max( 1, (int) $page ) - 1 ) * self::PER_PAGE;
		$uid    = $user instanceof WP_User ? (int) $user->ID : 0;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT o.id FROM `{$o}` o WHERE o.type = 'shop_order' AND ( o.billing_email = %s OR ( %d > 0 AND o.customer_id = %d ) OR EXISTS ( SELECT 1 FROM `{$a}` a WHERE a.order_id = o.id AND a.email = %s ) ) ORDER BY o.id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$email_address,
					$uid,
					$uid,
					$email_address,
					self::PER_PAGE,
					$offset
				)
			)
		);
	}

	/** One archived order as rows for the export file. */
	private static function order_rows( $id ) {
		global $wpdb;
		$o     = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'wc_orders' );
		$a     = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'wc_order_addresses' );
		$i     = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'woocommerce_order_items' );
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$o}` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $order ) {
			return array();
		}
		$addresses = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$a}` WHERE order_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items     = $wpdb->get_col( $wpdb->prepare( "SELECT order_item_name FROM `{$i}` WHERE order_id = %d AND order_item_type = 'line_item'", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$data = array(
			array(
				'name'  => __( 'Order number', 'nextial-order-archive' ),
				'value' => '#' . $id,
			),
			array(
				'name'  => __( 'Order date', 'nextial-order-archive' ),
				'value' => $order['date_created_gmt'] ? get_date_from_gmt( $order['date_created_gmt'] ) : '',
			),
			array(
				'name'  => __( 'Order status', 'nextial-order-archive' ),
				'value' => preg_replace( '/^wc-/', '', (string) $order['status'] ),
			),
			array(
				'name'  => __( 'Order total', 'nextial-order-archive' ),
				'value' => $order['total_amount'] . ' ' . $order['currency'],
			),
			array(
				'name'  => __( 'Items', 'nextial-order-archive' ),
				'value' => implode( ', ', array_map( 'strval', $items ) ),
			),
		);
		$fields = array(
			'first_name' => __( 'First name', 'nextial-order-archive' ),
			'last_name'  => __( 'Last name', 'nextial-order-archive' ),
			'company'    => __( 'Company', 'nextial-order-archive' ),
			'address_1'  => __( 'Address line 1', 'nextial-order-archive' ),
			'address_2'  => __( 'Address line 2', 'nextial-order-archive' ),
			'city'       => __( 'City', 'nextial-order-archive' ),
			'state'      => __( 'State', 'nextial-order-archive' ),
			'postcode'   => __( 'Postcode', 'nextial-order-archive' ),
			'country'    => __( 'Country', 'nextial-order-archive' ),
			'email'      => __( 'E-mail', 'nextial-order-archive' ),
			'phone'      => __( 'Phone', 'nextial-order-archive' ),
		);
		foreach ( (array) $addresses as $address ) {
			$billing = 'billing' === $address['address_type'];
			foreach ( $fields as $key => $label ) {
				if ( '' === (string) $address[ $key ] || null === $address[ $key ] ) {
					continue;
				}
				$data[] = array(
					'name'  => ( $billing
						/* translators: %s: field name, for example "City" */
						? sprintf( __( 'Billing %s', 'nextial-order-archive' ), $label )
						/* translators: %s: field name, for example "City" */
						: sprintf( __( 'Shipping %s', 'nextial-order-archive' ), $label ) ),
					'value' => $address[ $key ],
				);
			}
		}
		if ( ! empty( $order['billing_email'] ) ) {
			$data[] = array(
				'name'  => __( 'Order e-mail', 'nextial-order-archive' ),
				'value' => $order['billing_email'],
			);
		}
		if ( ! empty( $order['customer_note'] ) ) {
			$data[] = array(
				'name'  => __( 'Customer note', 'nextial-order-archive' ),
				'value' => $order['customer_note'],
			);
		}
		return $data;
	}

	/**
	 * Exporter callback: archived orders of this person.
	 *
	 * @param string $email_address E-mail from the request.
	 * @param int    $page          1-based page.
	 */
	public static function export( $email_address, $page = 1 ) {
		$ids   = self::order_ids( $email_address, $page );
		$items = array();
		foreach ( $ids as $id ) {
			$data = self::order_rows( $id );
			if ( $data ) {
				$items[] = array(
					'group_id'    => 'nextial-order-archive',
					'group_label' => __( 'Archived orders', 'nextial-order-archive' ),
					'item_id'     => 'archived-order-' . $id,
					'data'        => $data,
				);
			}
		}
		return array(
			'data' => $items,
			'done' => count( $ids ) < self::PER_PAGE,
		);
	}

	/**
	 * Eraser callback: anonymise archived orders of this person, with the
	 * same rule and the same fields WooCommerce uses for live orders.
	 * If WooCommerce is set to keep order data (the default), nothing is
	 * changed and the orders are reported as retained.
	 *
	 * @param string $email_address E-mail from the request.
	 * @param int    $page          1-based page.
	 */
	public static function erase( $email_address, $page = 1 ) {
		$enabled = function_exists( 'wc_string_to_bool' )
			? wc_string_to_bool( get_option( 'woocommerce_erasure_request_removes_order_data', 'no' ) )
			: false;
		// When data really is removed, an anonymised order no longer matches
		// the request, so every call takes the first page of what is left.
		// Skipping pages would leave the last orders untouched.
		$ids      = self::order_ids( $email_address, $enabled ? 1 : $page );
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $ids ) < self::PER_PAGE,
		);
		if ( empty( $ids ) ) {
			return $response;
		}
		if ( ! $enabled ) {
			$response['items_retained'] = true;
			$response['messages'][]     = sprintf(
				/* translators: %d: number of orders */
				_n( '%d archived order was kept, like live orders: turn on "Remove personal data from orders on request" in WooCommerce → Settings → Accounts & Privacy.', '%d archived orders were kept, like live orders: turn on "Remove personal data from orders on request" in WooCommerce → Settings → Accounts & Privacy.', count( $ids ), 'nextial-order-archive' ),
				count( $ids )
			);
			return $response;
		}
		foreach ( $ids as $id ) {
			if ( self::anonymise( $id ) ) {
				$response['items_removed'] = true;
			}
		}
		if ( ! $response['items_removed'] ) {
			// Nothing changed: stop, so a request can never loop for ever.
			$response['done'] = true;
		}
		$response['messages'][] = sprintf(
			/* translators: %d: number of orders */
			_n( 'Personal data was removed from %d archived order.', 'Personal data was removed from %d archived orders.', count( $ids ), 'nextial-order-archive' ),
			count( $ids )
		);
		Nextial_OA_Log::add( 'anonymise', count( $ids ), '' );
		return $response;
	}

	/**
	 * Replace the personal data of one archived order (and its refunds) with
	 * anonymised values. Totals, items, dates and country stay, so reports
	 * and accounting still add up.
	 *
	 * @param int $order_id Archived order ID.
	 * @return bool True when something changed.
	 */
	public static function anonymise( $order_id ) {
		global $wpdb;
		$order_id = (int) $order_id;
		$o        = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'wc_orders' );
		$a        = Nextial_OA_Archiver::archive_table( $wpdb->prefix . 'wc_order_addresses' );
		$ids      = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$o}` WHERE id = %d OR parent_order_id = %d", $order_id, $order_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $ids ) ) {
			return false;
		}
		$changed = false;
		foreach ( $ids as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT billing_email, ip_address, user_agent, transaction_id, customer_id FROM `{$o}` WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $row ) {
				continue;
			}
			$update = array(
				'billing_email'  => self::anon( 'email', $row['billing_email'] ),
				'ip_address'     => self::anon( 'ip', $row['ip_address'] ),
				'user_agent'     => self::anon( 'text', $row['user_agent'] ),
				'transaction_id' => self::anon( 'numeric_id', $row['transaction_id'] ),
				'customer_id'    => 0,
				'customer_note'  => '',
			);
			$changed = (bool) $wpdb->update( $o, $update, array( 'id' => $id ) ) || $changed;

			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$a}` WHERE order_id = %d", $id ), ARRAY_A ) as $address ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$changed = (bool) $wpdb->update(
					$a,
					array(
						'first_name' => self::anon( 'text', $address['first_name'] ),
						'last_name'  => self::anon( 'text', $address['last_name'] ),
						'company'    => self::anon( 'text', $address['company'] ),
						'address_1'  => self::anon( 'text', $address['address_1'] ),
						'address_2'  => self::anon( 'text', $address['address_2'] ),
						'city'       => self::anon( 'text', $address['city'] ),
						'state'      => self::anon( 'address_state', $address['state'] ),
						'postcode'   => self::anon( 'text', $address['postcode'] ),
						'email'      => self::anon( 'email', $address['email'] ),
						'phone'      => self::anon( 'phone', $address['phone'] ),
					),
					array( 'id' => $address['id'] )
				) || $changed;
			}
			Nextial_OA_Archiver::flush_cache( $id );
		}
		/**
		 * Fires after an archived order was anonymised for a privacy request.
		 *
		 * @param int $order_id Archived order ID.
		 */
		do_action( 'nextial_oa_anonymised', $order_id );
		return $changed;
	}

	/** Anonymised value for one field; empty values stay empty. */
	private static function anon( $type, $value ) {
		if ( '' === (string) $value || null === $value ) {
			return '';
		}
		return function_exists( 'wp_privacy_anonymize_data' ) ? wp_privacy_anonymize_data( $type, $value ) : '';
	}
}
