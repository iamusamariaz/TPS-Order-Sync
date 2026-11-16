<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings (single option array).
 */
class TPSOS_Settings {

	const OPTION = 'tpsos_settings';

	public static function defaults() {
		return array(
			'environment'      => 'testing', // testing | live. Safe default.
			'api_base'         => 'https://perfumesw.com/api',
			'test_token'       => '',
			'live_token'       => '',
			'address_policy'   => 'strict',  // strict | accept_suggested | as_entered
			'order_prefix'     => '',
			'us_only'          => 'yes',
			'send_email'       => 'no',      // send customer email address to TPS
			'shipped_status'   => 'completed',
			'notify_admin'     => 'yes',
			'notify_customer'  => 'yes',
			'admin_email'      => '',
			'tracking_page_id' => 0,
			'poll_enabled'     => 'yes',
			'debug_log'        => 'yes',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Current environment: 'live' or 'testing'. */
	public static function env() {
		return 'live' === self::get( 'environment' ) ? 'live' : 'testing';
	}

	public static function is_live() {
		return 'live' === self::env();
	}

	/** API token for an environment. Testing falls back to the live token when no test token is set. */
	public static function token( $env = null ) {
		$env = $env ? $env : self::env();
		if ( 'live' === $env ) {
			return trim( (string) self::get( 'live_token' ) );
		}
		$test = trim( (string) self::get( 'test_token' ) );
		return '' !== $test ? $test : trim( (string) self::get( 'live_token' ) );
	}

	public static function admin_email() {
		$email = (string) self::get( 'admin_email' );
		return is_email( $email ) ? $email : get_option( 'admin_email' );
	}

	public static function sanitize( $input ) {
		$old = self::all();
		$out = $old;
		$input = is_array( $input ) ? $input : array();

		$out['environment'] = ( isset( $input['environment'] ) && 'live' === $input['environment'] ) ? 'live' : 'testing';

		$base = isset( $input['api_base'] ) ? esc_url_raw( trim( $input['api_base'] ) ) : '';
		$out['api_base'] = $base ? untrailingslashit( $base ) : $old['api_base'];

		// Tokens: blank keeps the stored value (they are never echoed back to the form).
		foreach ( array( 'test_token', 'live_token' ) as $k ) {
			$v = isset( $input[ $k ] ) ? trim( sanitize_text_field( $input[ $k ] ) ) : '';
			if ( '' !== $v ) {
				$out[ $k ] = $v;
			}
		}
		if ( ! empty( $input['clear_test_token'] ) ) {
			$out['test_token'] = '';
		}

		$policy = isset( $input['address_policy'] ) ? $input['address_policy'] : 'strict';
		$out['address_policy'] = in_array( $policy, array( 'strict', 'accept_suggested', 'as_entered' ), true ) ? $policy : 'strict';

		$out['order_prefix'] = isset( $input['order_prefix'] ) ? preg_replace( '/[^A-Za-z0-9\-_]/', '', $input['order_prefix'] ) : '';

		foreach ( array( 'us_only', 'send_email', 'notify_admin', 'notify_customer', 'poll_enabled', 'debug_log' ) as $k ) {
			$out[ $k ] = ! empty( $input[ $k ] ) ? 'yes' : 'no';
		}

		$out['shipped_status']   = isset( $input['shipped_status'] ) ? sanitize_key( $input['shipped_status'] ) : 'completed';
		$out['admin_email']      = isset( $input['admin_email'] ) && is_email( $input['admin_email'] ) ? sanitize_email( $input['admin_email'] ) : '';
		$out['tracking_page_id'] = isset( $input['tracking_page_id'] ) ? absint( $input['tracking_page_id'] ) : 0;

		return $out;
	}

	public static function log( $message, $level = 'info' ) {
		if ( 'yes' !== self::get( 'debug_log' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'tps-orders-sync' ) );
	}
}
