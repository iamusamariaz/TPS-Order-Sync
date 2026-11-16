<?php
/**
 * Plugin Name:       TPS Orders Sync
 * Plugin URI:        https://7thwebstudio.com
 * Description:       Automatically pushes paid WooCommerce dropship orders to The Perfume Spot (perfumesw.com DropShip API), tracks their status and sends tracking details to the admin and the customer.
 * Version:           1.0.0
 * Author:            Usama Riaz
 * Author URI:        https://7thwebstudio.com
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       tps-orders-sync
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPSOS_VERSION', '1.0.0' );
define( 'TPSOS_FILE', __FILE__ );
define( 'TPSOS_DIR', plugin_dir_path( __FILE__ ) );

require_once TPSOS_DIR . 'includes/class-tpsos-settings.php';
require_once TPSOS_DIR . 'includes/class-tpsos-db.php';
require_once TPSOS_DIR . 'includes/class-tpsos-api.php';
require_once TPSOS_DIR . 'includes/class-tpsos-carriers.php';
require_once TPSOS_DIR . 'includes/class-tpsos-emails.php';
require_once TPSOS_DIR . 'includes/class-tpsos-sync.php';
require_once TPSOS_DIR . 'includes/class-tpsos-frontend.php';
require_once TPSOS_DIR . 'includes/class-tpsos-admin.php';

/* ---- Cron schedule (must be registered at load time, before activation scheduling) ---- */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['tpsos_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (TPS Orders Sync)',
		);
		return $schedules;
	}
);

/* ---- HPOS compatibility ---- */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TPSOS_FILE, true );
		}
	}
);

/* ---- Activation / deactivation ---- */
register_activation_hook(
	__FILE__,
	function () {
		TPSOS_DB::install();

		if ( ! wp_next_scheduled( 'tpsos_poll' ) ) {
			wp_schedule_event( time() + 300, 'tpsos_15min', 'tpsos_poll' );
		}

		// Create the tracking page once.
		$settings = TPSOS_Settings::all();
		if ( empty( $settings['tracking_page_id'] ) ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => 'Track Your Order',
					'post_content' => '[tps_track]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				$settings['tracking_page_id'] = (int) $page_id;
				update_option( TPSOS_Settings::OPTION, $settings );
			}
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'tpsos_poll' );
	}
);

/* ---- Boot ---- */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>TPS Orders Sync</strong> requires WooCommerce to be installed and active.</p></div>';
				}
			);
			return;
		}

		TPSOS_DB::maybe_upgrade();
		TPSOS_Sync::init();
		TPSOS_Frontend::init();
		TPSOS_Admin::init();

		// Self-heal the polling schedule.
		add_action(
			'init',
			function () {
				if ( ! wp_next_scheduled( 'tpsos_poll' ) ) {
					wp_schedule_event( time() + 300, 'tpsos_15min', 'tpsos_poll' );
				}
			}
		);

		add_filter(
			'plugin_action_links_' . plugin_basename( TPSOS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=tpsos-settings' ) ) . '">Settings</a>' );
				return $links;
			}
		);
	}
);
