<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carrier detection and tracking URLs.
 */
class TPSOS_Carriers {

	const FALLBACK = 'https://t.17track.net/en#nums={n}';

	private static function templates() {
		return array(
			'usps'   => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={n}',
			'ups'    => 'https://www.ups.com/track?tracknum={n}',
			'fedex'  => 'https://www.fedex.com/fedextrack/?trknbr={n}',
			'dhl'    => 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?submit=1&tracking-id={n}',
			'ontrac' => 'https://www.ontrac.com/tracking/?number={n}',
			'gofo'   => self::FALLBACK,
		);
	}

	/** Guess the carrier from the shape of the tracking number. */
	public static function detect( $tracking ) {
		$n = strtoupper( preg_replace( '/\s+/', '', (string) $tracking ) );
		if ( preg_match( '/^1Z[0-9A-Z]{16}$/', $n ) ) {
			return 'ups';
		}
		if ( preg_match( '/^GF[A-Z]*\d{6,}$/', $n ) ) {
			return 'gofo';
		}
		if ( preg_match( '/^[CD]\d{14}$/', $n ) ) {
			return 'ontrac';
		}
		if ( preg_match( '/^9[2345]\d{18,24}$/', $n ) || preg_match( '/^[A-Z]{2}\d{9}US$/', $n ) ) {
			return 'usps';
		}
		if ( preg_match( '/^(\d{12}|\d{15})$/', $n ) ) {
			return 'fedex';
		}
		if ( preg_match( '/^\d{10}$/', $n ) ) {
			return 'dhl';
		}
		return '';
	}

	/** Map a carrier name from the API ("USPS Ground Advantage", "UPS"...) to a key. */
	public static function key_from_name( $name ) {
		$c = strtolower( (string) $name );
		if ( '' === $c ) {
			return '';
		}
		$map = array(
			'usps'   => 'usps',
			'postal' => 'usps',
			'fedex'  => 'fedex',
			'dhl'    => 'dhl',
			'ontrac' => 'ontrac',
			'gofo'   => 'gofo',
			'ups'    => 'ups',
		);
		foreach ( $map as $needle => $key ) {
			if ( false !== strpos( $c, $needle ) ) {
				return $key;
			}
		}
		return '';
	}

	public static function url( $tracking, $carrier = '' ) {
		$tracking = trim( (string) $tracking );
		if ( '' === $tracking ) {
			return '';
		}
		$key       = self::key_from_name( $carrier );
		$key       = $key ? $key : self::detect( $tracking );
		$templates = self::templates();
		$tpl       = isset( $templates[ $key ] ) ? $templates[ $key ] : self::FALLBACK;
		$url       = str_replace( '{n}', rawurlencode( preg_replace( '/\s+/', '', $tracking ) ), $tpl );
		return apply_filters( 'tpsos_tracking_url', $url, $tracking, $carrier, $key );
	}

	/** Best tracking URL for a DB row (TPS-provided URL wins). */
	public static function url_for_row( $row ) {
		if ( ! $row || '' === (string) $row->tracking_number ) {
			return '';
		}
		if ( ! empty( $row->tracking_url ) && preg_match( '#^https?://#i', $row->tracking_url ) ) {
			return $row->tracking_url;
		}
		return self::url( $row->tracking_number, $row->carrier );
	}
}
