<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin client for the TPS wholesale API.
 *
 * Every method returns a normalised array:
 *  ok, code (HTTP, 0 = network failure), type (error.type), error (message), data (decoded JSON),
 *  raw, retry_after (seconds), request_id.
 */
class TPSOS_API {

	public static function request( $method, $path, $params = array(), $body = null, $env = null ) {
		$env   = $env ? $env : TPSOS_Settings::env();
		$token = TPSOS_Settings::token( $env );

		$result = array(
			'ok'          => false,
			'code'        => 0,
			'type'        => '',
			'error'       => '',
			'data'        => null,
			'raw'         => '',
			'retry_after' => 0,
			'request_id'  => '',
		);

		if ( '' === $token ) {
			$result['type']  = 'no_token';
			$result['error'] = 'No API token is configured for the ' . $env . ' environment.';
			return $result;
		}

		$url = untrailingslashit( TPSOS_Settings::get( 'api_base' ) ) . '/' . ltrim( $path, '/' );
		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$result['type']  = 'network';
			$result['error'] = $response->get_error_message();
			TPSOS_Settings::log( "$method $path network error: " . $result['error'], 'error' );
			return $result;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		$result['code']        = $code;
		$result['raw']         = $raw;
		$result['data']        = is_array( $data ) ? $data : null;
		$result['retry_after'] = (int) wp_remote_retrieve_header( $response, 'retry-after' );
		$result['request_id']  = (string) wp_remote_retrieve_header( $response, 'x-request-id' );

		$failed = $code >= 400 || ( is_array( $data ) && isset( $data['success'] ) && false === $data['success'] );
		if ( $failed ) {
			$err = ( is_array( $data ) && isset( $data['error'] ) ) ? $data['error'] : null;
			if ( is_array( $err ) ) {
				$result['type']  = isset( $err['type'] ) ? (string) $err['type'] : '';
				$result['error'] = isset( $err['message'] ) ? (string) $err['message'] : '';
				if ( ! empty( $err['details'] ) ) {
					$result['error'] .= ' Details: ' . wp_json_encode( $err['details'] );
				}
			} elseif ( is_string( $err ) ) {
				$result['error'] = $err;
			}
			if ( '' === $result['error'] ) {
				$result['error'] = 'HTTP ' . $code;
			}
			if ( '' === $result['type'] ) {
				$result['type'] = 'http_' . $code;
			}
		} else {
			$result['ok'] = $code >= 200 && $code < 300;
		}

		TPSOS_Settings::log(
			sprintf( '%s %s -> HTTP %d%s%s', $method, $path, $code, $result['type'] ? ' [' . $result['type'] . ']' : '', $result['request_id'] ? ' req ' . $result['request_id'] : '' ),
			$result['ok'] ? 'info' : 'warning'
		);

		return $result;
	}

	public static function create_order( $payload, $env = null ) {
		return self::request( 'POST', 'createOrder', array(), $payload, $env );
	}

	public static function get_orders( $params = array(), $env = null ) {
		return self::request( 'GET', 'getOrders', $params, null, $env );
	}

	public static function get_tracking( $params = array(), $env = null ) {
		return self::request( 'GET', 'getTracking', $params, null, $env );
	}
}
