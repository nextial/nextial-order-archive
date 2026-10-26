<?php
/**
 * Short run log (last 50 runs), stored in an option.
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

class Nextial_OA_Log {

	const OPT = 'nextial_oa_log';

	public static function add( $action, $count, $error ) {
		$log   = self::all();
		$log[] = array(
			'at'     => time(),
			'action' => (string) $action,
			'count'  => (int) $count,
			'error'  => (string) $error,
			'user'   => get_current_user_id(),
		);
		update_option( self::OPT, array_slice( $log, -50 ), false );
	}

	public static function all() {
		$log = get_option( self::OPT, array() );
		return is_array( $log ) ? $log : array();
	}
}
