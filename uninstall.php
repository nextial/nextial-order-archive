<?php
/**
 * Uninstall: settings and the run log are removed. The archive tables are
 * KEPT on purpose: they hold your archived orders. Reinstall the plugin to
 * search or restore them. To delete the archive for good, restore what you
 * need first, then drop the tables whose names contain "nxoa_".
 *
 * @package NextialOrderArchive
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'nextial_oa_rule', 'nextial_oa_run', 'nextial_oa_log', 'nextial_oa_myaccount', 'nextial_oa_rewrite' ) as $nextial_oa_option ) {
	delete_option( $nextial_oa_option );
}
