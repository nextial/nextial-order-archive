<?php
/**
 * Plugin Name:          Nextial Order Archive – Archive Old Orders for WooCommerce (HPOS)
 * Description:          Keeps the WooCommerce Orders screen fast: moves old, finished orders into archive tables (HPOS), keeps them searchable and restores any order in one click.
 * Version:              0.1.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.2
 * Author:               Nextial
 * Author URI:           https://nextial.it/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          nextial-order-archive
 *
 * @package NextialOrderArchive
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXTIAL_OA_VERSION', '0.1.0' );
define( 'NEXTIAL_OA_FILE', __FILE__ );
define( 'NEXTIAL_OA_DIR', plugin_dir_path( __FILE__ ) );

require_once NEXTIAL_OA_DIR . 'includes/class-log.php';
require_once NEXTIAL_OA_DIR . 'includes/class-archiver.php';
require_once NEXTIAL_OA_DIR . 'includes/class-runner.php';
require_once NEXTIAL_OA_DIR . 'includes/class-admin.php';

// Declare HPOS compatibility (the plugin requires it).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		if ( true === Nextial_OA_Archiver::check_environment() ) {
			Nextial_OA_Archiver::install_tables();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Nextial_OA_Runner::HOOK_BATCH, array(), Nextial_OA_Runner::GROUP );
			as_unschedule_all_actions( Nextial_OA_Runner::HOOK_DAILY, array(), Nextial_OA_Runner::GROUP );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Nextial_OA_Runner::init();
		if ( is_admin() ) {
			Nextial_OA_Admin::init();
		}
	}
);
