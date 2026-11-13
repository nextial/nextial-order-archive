<?php
/**
 * Background runs with Action Scheduler (bundled with WooCommerce):
 * small batches, one after the other, until nothing matches or the run
 * is stopped. Optional daily automatic run.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OA_Runner {

	const HOOK_BATCH = 'nextial_oa_batch';
	const HOOK_DAILY = 'nextial_oa_daily';
	const GROUP      = 'nextial-order-archive';
	const OPT_RULE   = 'nextial_oa_rule';
	const OPT_RUN    = 'nextial_oa_run';

	public static function init() {
		add_action( self::HOOK_BATCH, array( __CLASS__, 'batch' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'schedule_daily' ), 20 );
	}

	public static function default_rule() {
		return array(
			'statuses'        => array( 'completed', 'refunded', 'cancelled' ),
			'older_than_days' => 365,
			'batch_size'      => 50,
			'auto'            => false,
		);
	}

	public static function rule() {
		$r = get_option( self::OPT_RULE, array() );
		return wp_parse_args( is_array( $r ) ? $r : array(), self::default_rule() );
	}

	public static function save_rule( array $r ) {
		$rule = array(
			'statuses'        => array_values( array_intersect( (array) ( $r['statuses'] ?? array() ), Nextial_OA_Archiver::ALLOWED_STATUSES ) ),
			'older_than_days' => max( (int) apply_filters( 'nextial_oa_min_days', 30 ), min( 3650, (int) ( $r['older_than_days'] ?? 365 ) ) ),
			'batch_size'      => max( 10, min( 200, (int) ( $r['batch_size'] ?? 50 ) ) ),
			'auto'            => ! empty( $r['auto'] ),
		);
		update_option( self::OPT_RULE, $rule, false );
		self::schedule_daily();
		return $rule;
	}

	public static function schedule_daily() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}
		$auto = self::rule()['auto'];
		$next = as_next_scheduled_action( self::HOOK_DAILY, array(), self::GROUP );
		if ( $auto && ! $next ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:00' ), DAY_IN_SECONDS, self::HOOK_DAILY, array(), self::GROUP );
		} elseif ( ! $auto && $next ) {
			as_unschedule_all_actions( self::HOOK_DAILY, array(), self::GROUP );
		}
	}

	/** Current run state, or null. */
	public static function run() {
		$r = get_option( self::OPT_RUN, null );
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Start a background run.
	 *
	 * @param string $mode 'archive' (apply the rule) or 'restore' (bring back
	 *                     every archived order matching $term).
	 * @param string $term Search term for 'restore' ('' = the whole archive).
	 */
	public static function start( $mode = 'archive', $term = '' ) {
		if ( true !== Nextial_OA_Archiver::check_environment() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$current = self::run();
		if ( $current && 'running' === $current['status'] && ( time() - (int) $current['started'] ) < 6 * HOUR_IN_SECONDS ) {
			return false; // Already running.
		}
		update_option(
			self::OPT_RUN,
			array(
				'started' => time(),
				'moved'   => 0,
				'batches' => 0,
				'status'  => 'running',
				'error'   => '',
				'mode'    => 'restore' === $mode ? 'restore' : 'archive',
				'term'    => 'restore' === $mode ? (string) $term : '',
				'skipped' => array(),
			),
			false
		);
		as_enqueue_async_action( self::HOOK_BATCH, array(), self::GROUP );
		return true;
	}

	public static function stop() {
		$run = self::run();
		if ( $run && 'running' === $run['status'] ) {
			$run['status'] = 'stopped';
			update_option( self::OPT_RUN, $run, false );
		}
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_BATCH, array(), self::GROUP );
		}
	}

	public static function daily() {
		if ( self::rule()['auto'] ) {
			$run = self::run();
			if ( ! $run || 'running' !== $run['status'] ) {
				self::start();
			}
		}
	}

	/**
	 * One batch. Schedules the next one while there is work left.
	 */
	public static function batch() {
		$run = self::run();
		if ( ! $run || 'running' !== $run['status'] ) {
			return;
		}
		$rule = self::rule();
		if ( 'restore' === ( $run['mode'] ?? 'archive' ) ) {
			self::restore_batch( $run, (int) $rule['batch_size'] );
			return;
		}
		$c    = Nextial_OA_Archiver::candidates( $rule, (int) $rule['batch_size'] );
		if ( empty( $c['ids'] ) ) {
			$run['status'] = 'done';
			update_option( self::OPT_RUN, $run, false );
			return;
		}
		$res = Nextial_OA_Archiver::archive( $c['ids'] );
		++$run['batches'];
		if ( ! $res['ok'] ) {
			$run['status'] = 'error';
			$run['error']  = $res['error'];
			update_option( self::OPT_RUN, $run, false );
			return;
		}
		$run['moved'] += $res['moved'];
		update_option( self::OPT_RUN, $run, false );
		as_schedule_single_action( time() + 5, self::HOOK_BATCH, array(), self::GROUP );
	}

	/**
	 * One restore batch. Orders that cannot be restored (for example an ID
	 * conflict) are skipped and listed, the others keep going.
	 */
	private static function restore_batch( array $run, $size ) {
		$skipped = array_map( 'intval', (array) ( $run['skipped'] ?? array() ) );
		$ids     = Nextial_OA_Archiver::archived_ids( (string) ( $run['term'] ?? '' ), $size, $skipped );
		if ( empty( $ids ) ) {
			$run['status'] = 'done';
			update_option( self::OPT_RUN, $run, false );
			return;
		}
		$res = Nextial_OA_Archiver::restore_many( $ids );
		++$run['batches'];
		$run['moved'] += $res['restored'];
		if ( $res['failed'] ) {
			$busy = false;
			foreach ( $res['failed'] as $id => $error ) {
				if ( false !== strpos( $error, 'Another archive or restore is running' ) ) {
					$busy = true; // Not the order's fault: try it again next batch.
					continue;
				}
				$run['skipped'][] = (int) $id;
				$run['error']     = $error;
			}
			if ( $busy && 0 === $res['restored'] && count( $run['skipped'] ) === count( $skipped ) ) {
				$run['busy'] = (int) ( $run['busy'] ?? 0 ) + 1;
				if ( $run['busy'] > 20 ) {
					$run['status'] = 'error';
					$run['error']  = 'Another archive or restore kept running; restore stopped.';
					update_option( self::OPT_RUN, $run, false );
					return;
				}
			}
		}
		update_option( self::OPT_RUN, $run, false );
		as_schedule_single_action( time() + 5, self::HOOK_BATCH, array(), self::GROUP );
	}
}
