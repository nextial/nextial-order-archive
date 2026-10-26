<?php
/**
 * The archive engine.
 *
 * Moves whole orders (order row, addresses, operational data, meta, line
 * items and their meta, order notes, and child refunds) from the live HPOS
 * tables into mirror tables with the "nxoa_" prefix, and back.
 *
 * Every batch runs in one database transaction: rows are copied, the copies
 * are counted against the originals, and only then are the originals
 * deleted. Any difference rolls the whole batch back.
 *
 * Analytics tables (wc_order_stats and the lookup tables) are left alone,
 * so WooCommerce reports keep their history. The placeholder posts that HPOS
 * keeps in wp_posts are also left alone: they reserve the order IDs, so a
 * restored order gets its own ID back.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names come from $wpdb->prefix and a fixed list; every value goes through $wpdb->prepare() or is cast to int.

class Nextial_OA_Archiver {

	const ARCHIVE_PREFIX = 'nxoa_';

	/** Statuses that may be archived (without the wc- prefix). */
	const ALLOWED_STATUSES = array( 'completed', 'refunded', 'cancelled', 'failed', 'pending', 'checkout-draft' );

	/**
	 * Live tables handled, keyed by short name, with the column that links a
	 * row to the order ("id" for the orders table itself).
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'orders'      => array( 'table' => $wpdb->prefix . 'wc_orders', 'key' => 'id' ),
			'addresses'   => array( 'table' => $wpdb->prefix . 'wc_order_addresses', 'key' => 'order_id' ),
			'operational' => array( 'table' => $wpdb->prefix . 'wc_order_operational_data', 'key' => 'order_id' ),
			'meta'        => array( 'table' => $wpdb->prefix . 'wc_orders_meta', 'key' => 'order_id' ),
			'items'       => array( 'table' => $wpdb->prefix . 'woocommerce_order_items', 'key' => 'order_id' ),
			'itemmeta'    => array( 'table' => $wpdb->prefix . 'woocommerce_order_itemmeta', 'key' => 'order_item_id', 'via' => 'items' ),
			'notes'       => array( 'table' => $wpdb->comments, 'key' => 'comment_post_ID', 'where' => "comment_type = 'order_note'" ),
			'notemeta'    => array( 'table' => $wpdb->commentmeta, 'key' => 'comment_id', 'via' => 'notes' ),
		);
	}

	public static function archive_table( $live ) {
		global $wpdb;
		return $wpdb->prefix . self::ARCHIVE_PREFIX . substr( $live, strlen( $wpdb->prefix ) );
	}

	/* ------------------------------------------------------------------ */
	/* Preconditions                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Can we run on this site? Returns true or an error message.
	 *
	 * @return true|string
	 */
	public static function check_environment() {
		global $wpdb;
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_container' ) ) {
			return __( 'WooCommerce is not active.', 'nextial-order-archive' );
		}
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) || ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return __( 'This plugin needs High-Performance Order Storage (HPOS). Turn it on in WooCommerce → Settings → Advanced → Features.', 'nextial-order-archive' );
		}
		if ( 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ) ) {
			return __( 'Compatibility mode (order data sync with the posts table) is on. Turn it off in WooCommerce → Settings → Advanced → Features before archiving, so orders are stored in one place only.', 'nextial-order-archive' );
		}
		foreach ( self::tables() as $t ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t['table'] ) ) !== $t['table'] ) {
				/* translators: %s: table name */
				return sprintf( __( 'Table %s is missing.', 'nextial-order-archive' ), $t['table'] );
			}
		}
		return true;
	}

	/**
	 * Create the archive tables (same structure as the live ones).
	 */
	public static function install_tables() {
		global $wpdb;
		foreach ( self::tables() as $t ) {
			$archive = self::archive_table( $t['table'] );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $archive ) ) !== $archive ) {
				$wpdb->query( "CREATE TABLE `{$archive}` LIKE `{$t['table']}`" );
			}
		}
	}

	/**
	 * Columns present in both tables, so a WooCommerce schema change never
	 * breaks a copy (new columns get their default value on restore).
	 */
	private static function common_columns( $a, $b ) {
		global $wpdb;
		$ca = $wpdb->get_col( "SHOW COLUMNS FROM `{$a}`" );
		$cb = $wpdb->get_col( "SHOW COLUMNS FROM `{$b}`" );
		$common = array_values( array_intersect( $ca, $cb ) );
		return '`' . implode( '`,`', $common ) . '`';
	}

	/* ------------------------------------------------------------------ */
	/* Selection                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Orders matching a rule, oldest first, without the excluded ones.
	 *
	 * @param array $rule  statuses (string[]), older_than_days (int).
	 * @param int   $limit Max IDs.
	 * @return array{ids:int[], excluded:array<string,int>}
	 */
	public static function candidates( array $rule, $limit = 50 ) {
		global $wpdb;
		$orders   = $wpdb->prefix . 'wc_orders';
		$statuses = array_values( array_intersect( (array) ( $rule['statuses'] ?? array() ), self::ALLOWED_STATUSES ) );
		$days     = max( 0, (int) ( $rule['older_than_days'] ?? 365 ) );
		if ( empty( $statuses ) ) {
			return array(
				'ids'      => array(),
				'excluded' => array(),
			);
		}
		$wc_statuses = array_map(
			static function ( $s ) {
				return 'checkout-draft' === $s ? 'checkout-draft' : 'wc-' . $s;
			},
			$statuses
		);
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$in       = implode( ',', array_fill( 0, count( $wc_statuses ), '%s' ) );
		$limit    = max( 1, (int) $limit );
		$excluded = array(
			'subscription' => 0,
			'downloads'    => 0,
			'restored'     => 0,
		);
		$keep     = array();
		$offset   = 0;
		$chunk    = 200;
		while ( count( $keep ) < $limit && $offset < 100000 ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT id FROM {$orders} WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt < %s ORDER BY date_created_gmt ASC, id ASC LIMIT %d OFFSET %d",
						array_merge( $wc_statuses, array( $cutoff, $chunk, $offset ) )
					)
				)
			);
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$reason = self::exclusion_reason( $id );
				if ( $reason ) {
					$excluded[ $reason ] = ( $excluded[ $reason ] ?? 0 ) + 1;
					continue;
				}
				$keep[] = $id;
				if ( count( $keep ) >= $limit ) {
					break;
				}
			}
			$offset += $chunk;
		}
		return array(
			'ids'      => $keep,
			'excluded' => $excluded,
		);
	}

	/**
	 * Why an order must stay live, or '' if it can be archived.
	 */
	public static function exclusion_reason( $order_id ) {
		global $wpdb;
		$order_id = (int) $order_id;
		// Orders linked to WooCommerce Subscriptions (parent or renewal of any subscription).
		$sub = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_subscription' AND parent_order_id = %d",
				$order_id
			)
		);
		$renew = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key IN ('_subscription_renewal','_subscription_resubscribe','_subscription_switch')",
				$order_id
			)
		);
		if ( (int) $sub > 0 || (int) $renew > 0 ) {
			return 'subscription';
		}
		// Restored by hand: the shop owner wants it live.
		$restored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %d AND meta_key = '_nextial_oa_restored'",
				$order_id
			)
		);
		if ( (int) $restored > 0 ) {
			return 'restored';
		}
		// Orders with downloads the customer may still use.
		$perm_table = $wpdb->prefix . 'woocommerce_downloadable_product_permissions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $perm_table ) ) === $perm_table ) {
			$live = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$perm_table} WHERE order_id = %d AND ( access_expires IS NULL OR access_expires > %s )",
					$order_id,
					gmdate( 'Y-m-d H:i:s' )
				)
			);
			if ( (int) $live > 0 ) {
				return 'downloads';
			}
		}
		/**
		 * Return a non-empty string to keep an order live.
		 *
		 * @param string $reason   '' to allow archiving.
		 * @param int    $order_id Order ID.
		 */
		return (string) apply_filters( 'nextial_oa_exclusion_reason', '', $order_id );
	}

	/**
	 * Count matching orders (dry run).
	 *
	 * @return array{total:int, archivable:int, excluded:array<string,int>, oldest:string|null, newest:string|null}
	 */
	public static function preview( array $rule ) {
		global $wpdb;
		$orders   = $wpdb->prefix . 'wc_orders';
		$statuses = array_values( array_intersect( (array) ( $rule['statuses'] ?? array() ), self::ALLOWED_STATUSES ) );
		$out      = array(
			'total'      => 0,
			'archivable' => 0,
			'excluded'   => array(
				'subscription' => 0,
				'downloads'    => 0,
			),
			'oldest'     => null,
			'newest'     => null,
		);
		if ( empty( $statuses ) ) {
			return $out;
		}
		$wc_statuses = array_map(
			static function ( $s ) {
				return 'checkout-draft' === $s ? 'checkout-draft' : 'wc-' . $s;
			},
			$statuses
		);
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 0, (int) ( $rule['older_than_days'] ?? 365 ) ) * DAY_IN_SECONDS );
		$in     = implode( ',', array_fill( 0, count( $wc_statuses ), '%s' ) );
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$orders} WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt < %s ORDER BY date_created_gmt ASC LIMIT 20000",
				array_merge( $wc_statuses, array( $cutoff ) )
			)
		);
		$out['total'] = count( $ids );
		foreach ( $ids as $id ) {
			$reason = self::exclusion_reason( $id );
			if ( $reason ) {
				$out['excluded'][ $reason ] = ( $out['excluded'][ $reason ] ?? 0 ) + 1;
			} else {
				++$out['archivable'];
			}
		}
		if ( $ids ) {
			$range         = $wpdb->get_row(
				$wpdb->prepare( "SELECT MIN(date_created_gmt) AS a, MAX(date_created_gmt) AS b FROM {$orders} WHERE type = 'shop_order' AND status IN ({$in}) AND date_created_gmt < %s", array_merge( $wc_statuses, array( $cutoff ) ) ),
				ARRAY_A
			);
			$out['oldest'] = $range['a'];
			$out['newest'] = $range['b'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Move                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Archive these orders (with their refunds). All or nothing.
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return array{ok:bool, moved:int, error:string}
	 */
	public static function archive( array $order_ids ) {
		return self::move( $order_ids, 'archive' );
	}

	/**
	 * Restore archived orders (with their refunds). All or nothing.
	 *
	 * @param int[] $order_ids Order IDs.
	 * @return array{ok:bool, moved:int, error:string}
	 */
	public static function restore( array $order_ids ) {
		return self::move( $order_ids, 'restore' );
	}

	private static function move( array $order_ids, $direction ) {
		global $wpdb;
		// One move at a time on this site: counts, checks and the transaction
		// all happen under the lock (two runs could otherwise deadlock).
		$lock = (int) $wpdb->get_var( "SELECT GET_LOCK('nextial_oa_move', 15)" );
		if ( 1 !== $lock ) {
			return array( 'ok' => false, 'moved' => 0, 'error' => 'Another archive or restore is running; try again in a moment.' );
		}
		try {
			return self::move_locked( $order_ids, $direction );
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('nextial_oa_move')" );
		}
	}

	private static function move_locked( array $order_ids, $direction ) {
		global $wpdb;
		$env = self::check_environment();
		if ( true !== $env ) {
			return array( 'ok' => false, 'moved' => 0, 'error' => $env );
		}
		self::install_tables();
		$order_ids = array_values( array_unique( array_filter( array_map( 'intval', $order_ids ) ) ) );
		if ( empty( $order_ids ) ) {
			return array( 'ok' => true, 'moved' => 0, 'error' => '' );
		}
		$t = self::tables();

		// Source and destination per table.
		$src = array();
		$dst = array();
		foreach ( $t as $k => $def ) {
			$live      = $def['table'];
			$arch      = self::archive_table( $live );
			$src[ $k ] = 'archive' === $direction ? $live : $arch;
			$dst[ $k ] = 'archive' === $direction ? $arch : $live;
		}

		// Add child refunds.
		$ids_sql = implode( ',', $order_ids );
		$refunds = array_map( 'intval', $wpdb->get_col( "SELECT id FROM `{$src['orders']}` WHERE type = 'shop_order_refund' AND parent_order_id IN ({$ids_sql})" ) );
		$all     = array_values( array_unique( array_merge( $order_ids, $refunds ) ) );
		$all_sql = implode( ',', $all );

		// Only orders that really are in the source.
		$found = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$src['orders']}` WHERE id IN ({$ids_sql}) AND type = 'shop_order'" );
		if ( $found !== count( $order_ids ) ) {
			return array( 'ok' => false, 'moved' => 0, 'error' => 'archive' === $direction ? 'Some orders are no longer in the live tables.' : 'Some orders are not in the archive.' );
		}
		// Never overwrite: none of these IDs may exist in the destination.
		$clash = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$dst['orders']}` WHERE id IN ({$all_sql})" );
		if ( $clash > 0 ) {
			return array( 'ok' => false, 'moved' => 0, 'error' => 'Some order IDs already exist in the destination tables; nothing was moved.' );
		}

		// Row selectors per table.
		$where = array();
		foreach ( $t as $k => $def ) {
			if ( 'itemmeta' === $k ) {
				$where[ $k ] = "`{$def['key']}` IN (SELECT order_item_id FROM `{$src['items']}` WHERE order_id IN ({$all_sql}))";
			} elseif ( 'notemeta' === $k ) {
				$where[ $k ] = "`{$def['key']}` IN (SELECT comment_ID FROM `{$src['notes']}` WHERE comment_type = 'order_note' AND comment_post_ID IN ({$all_sql}))";
			} else {
				$where[ $k ] = "`{$def['key']}` IN ({$all_sql})" . ( isset( $def['where'] ) ? ' AND ' . $def['where'] : '' );
			}
		}
		// Metadata first must be read before the parent rows are gone: count everything up front.
		$counts = array();
		foreach ( $t as $k => $def ) {
			$counts[ $k ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$src[ $k ]}` WHERE {$where[ $k ]}" );
		}

		$fail = '';
		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$fail = self::transaction( $t, $src, $dst, $where, $counts );
			if ( '' === $fail || ! preg_match( '/deadlock|lock wait timeout/i', $fail ) ) {
				break;
			}
			usleep( 300000 * $attempt ); // Retryable: wait and try the whole batch again.
		}
		if ( '' !== $fail ) {
			Nextial_OA_Log::add( $direction, 0, $fail );
			return array( 'ok' => false, 'moved' => 0, 'error' => $fail );
		}

		if ( 'restore' === $direction ) {
			// Remember the restore, so automatic runs do not archive it again.
			foreach ( $order_ids as $id ) {
				$wpdb->insert(
					$wpdb->prefix . 'wc_orders_meta',
					array(
						'order_id'   => $id,
						'meta_key'   => '_nextial_oa_restored', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value' => (string) time(), // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
			}
		}
		foreach ( $all as $id ) {
			self::flush_cache( $id );
		}
		Nextial_OA_Log::add( $direction, count( $order_ids ), '' );
		/**
		 * Fires after orders were archived or restored.
		 *
		 * @param int[]  $order_ids Orders moved (refunds not included).
		 * @param string $direction 'archive' or 'restore'.
		 */
		do_action( 'nextial_oa_moved', $order_ids, $direction );
		return array( 'ok' => true, 'moved' => count( $order_ids ), 'error' => '' );
	}

	/**
	 * Copy, check, delete, inside one transaction. Returns '' on success
	 * (committed) or the reason it was rolled back.
	 */
	private static function transaction( array $t, array $src, array $dst, array $where, array $counts ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		foreach ( $t as $k => $def ) {
			$cols = self::common_columns( $src[ $k ], $dst[ $k ] );
			$res  = $wpdb->query( "INSERT INTO `{$dst[ $k ]}` ({$cols}) SELECT {$cols} FROM `{$src[ $k ]}` WHERE {$where[ $k ]}" );
			if ( false === $res || (int) $res !== $counts[ $k ] ) {
				$err = sprintf( 'Copy check failed for %s (expected %d rows, copied %s). %s', $k, $counts[ $k ], var_export( $res, true ), $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$wpdb->query( 'ROLLBACK' );
				return $err;
			}
		}
		foreach ( array( 'itemmeta', 'notemeta', 'items', 'notes', 'meta', 'addresses', 'operational', 'orders' ) as $k ) {
			$res = $wpdb->query( "DELETE FROM `{$src[ $k ]}` WHERE {$where[ $k ]}" );
			if ( false === $res || (int) $res !== $counts[ $k ] ) {
				$err = sprintf( 'Delete check failed for %s (expected %d rows, deleted %s). %s', $k, $counts[ $k ], var_export( $res, true ), $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
				$wpdb->query( 'ROLLBACK' );
				return $err;
			}
		}
		$wpdb->query( 'COMMIT' );
		return '';
	}

	private static function flush_cache( $id ) {
		wp_cache_delete( $id, 'orders' );
		wp_cache_delete( 'order-' . $id, 'orders' );
		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $id );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Cache not available: nothing to clear.
			}
		}
		clean_post_cache( $id );
	}

	/* ------------------------------------------------------------------ */
	/* Archived orders                                                     */
	/* ------------------------------------------------------------------ */

	public static function archived_count() {
		global $wpdb;
		$a = self::archive_table( $wpdb->prefix . 'wc_orders' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $a ) ) !== $a ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$a}` WHERE type = 'shop_order'" );
	}

	/**
	 * Search archived orders by ID, e-mail or name.
	 *
	 * @return array[]
	 */
	public static function search_archived( $term, $limit = 50 ) {
		global $wpdb;
		$o = self::archive_table( $wpdb->prefix . 'wc_orders' );
		$a = self::archive_table( $wpdb->prefix . 'wc_order_addresses' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $o ) ) !== $o ) {
			return array();
		}
		$term  = trim( (string) $term );
		$limit = max( 1, min( 200, (int) $limit ) );
		$sql   = "SELECT o.id, o.status, o.total_amount, o.currency, o.date_created_gmt, o.billing_email, ba.first_name, ba.last_name
			FROM `{$o}` o LEFT JOIN `{$a}` ba ON ba.order_id = o.id AND ba.address_type = 'billing'
			WHERE o.type = 'shop_order'";
		if ( '' === $term ) {
			return $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY o.date_created_gmt DESC LIMIT %d', $limit ), ARRAY_A );
		}
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		return $wpdb->get_results(
			$wpdb->prepare(
				$sql . ' AND ( o.id = %d OR o.billing_email LIKE %s OR ba.first_name LIKE %s OR ba.last_name LIKE %s OR CONCAT(ba.first_name, \' \', ba.last_name) LIKE %s ) ORDER BY o.date_created_gmt DESC LIMIT %d',
				ctype_digit( ltrim( $term, '#' ) ) ? (int) ltrim( $term, '#' ) : 0,
				$like,
				$like,
				$like,
				$like,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Fingerprint of an order's rows (for tests and the integrity check):
	 * row count and a checksum per table, wherever the order lives.
	 *
	 * @return array<string,string>
	 */
	public static function fingerprint( $order_id, $where_live = true ) {
		global $wpdb;
		$order_id = (int) $order_id;
		$out      = array();
		foreach ( self::tables() as $k => $def ) {
			$tbl   = $where_live ? $def['table'] : self::archive_table( $def['table'] );
			$items = $where_live ? $wpdb->prefix . 'woocommerce_order_items' : self::archive_table( $wpdb->prefix . 'woocommerce_order_items' );
			$notes = $where_live ? $wpdb->comments : self::archive_table( $wpdb->comments );
			if ( 'itemmeta' === $k ) {
				$w = "`order_item_id` IN (SELECT order_item_id FROM `{$items}` WHERE order_id = {$order_id})";
			} elseif ( 'notemeta' === $k ) {
				$w = "`comment_id` IN (SELECT comment_ID FROM `{$notes}` WHERE comment_type = 'order_note' AND comment_post_ID = {$order_id})";
			} elseif ( 'meta' === $k ) {
				$w = "`order_id` = {$order_id} AND meta_key <> '_nextial_oa_restored'";
			} else {
				$w = "`{$def['key']}` = {$order_id}" . ( isset( $def['where'] ) ? ' AND ' . $def['where'] : '' );
			}
			$rows      = $wpdb->get_results( "SELECT * FROM `{$tbl}` WHERE {$w}", ARRAY_A );
			$rows      = is_array( $rows ) ? $rows : array();
			$norm      = array_map( 'wp_json_encode', $rows );
			sort( $norm );
			$out[ $k ] = count( $rows ) . ':' . md5( implode( "\n", $norm ) );
		}
		return $out;
	}
}
