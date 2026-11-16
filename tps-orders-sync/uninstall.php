<?php
/**
 * Uninstall: remove settings and scheduled events.
 * The tps_orders table is intentionally kept so order history is never lost by accident.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tpsos_settings' );
delete_option( 'tpsos_last_poll' );
wp_clear_scheduled_hook( 'tpsos_poll' );
