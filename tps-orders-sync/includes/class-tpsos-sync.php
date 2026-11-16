<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync engine: paid Woo order -> TPS createOrder, then poll getOrders for shipment updates.
 *
 * Environment behaviour:
 *  - testing: createOrder is called with validate_only = true. Nothing is placed or charged,
 *             the Woo order is never touched, and no polling happens.
 *  - live:    the order is placed (and the saved TPS card is charged), then polled until shipped.
 */
class TPSOS_Sync {

	public static $suppress_completed_email = false;

	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ), 20 );
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'on_hold' ), 20 );

		add_action( 'tpsos_send_order', array( __CLASS__, 'send' ), 10, 1 );
		add_action( 'tpsos_poll', array( __CLASS__, 'poll' ) );

		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( __CLASS__, 'maybe_suppress_completed_email' ), 20, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Triggers                                                            */
	/* ------------------------------------------------------------------ */

	public static function is_paid( $order ) {
		$paid = $order->is_paid() && ( $order->get_date_paid() || (float) $order->get_total() <= 0 );
		return (bool) apply_filters( 'tpsos_order_is_paid', $paid, $order );
	}

	private static function should_sync( $order ) {
		return (bool) apply_filters( 'tpsos_should_sync_order', true, $order );
	}

	public static function ext_number( $order ) {
		return TPSOS_Settings::get( 'order_prefix' ) . $order->get_order_number();
	}

	/** Order is on hold / unpaid: keep a visible record, do not send. */
	public static function on_hold( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::should_sync( $order ) ) {
			return;
		}
		$env = TPSOS_Settings::env();
		if ( TPSOS_DB::find( $order_id, $env ) ) {
			return;
		}
		TPSOS_DB::save(
			$order_id,
			$env,
			array(
				'ext_order_number' => self::ext_number( $order ),
				'sync_status'      => 'awaiting_payment',
			)
		);
	}

	/** Order became paid: queue an automatic send. */
	public static function on_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::should_sync( $order ) ) {
			return;
		}
		$env = TPSOS_Settings::env();
		$row = TPSOS_DB::find( $order_id, $env );

		if ( $row && 'awaiting_payment' !== $row->sync_status ) {
			return; // Already queued / sent / handled.
		}

		TPSOS_DB::save(
			$order_id,
			$env,
			array(
				'ext_order_number' => self::ext_number( $order ),
				'sync_status'      => 'pending',
			)
		);

		if ( ! wp_next_scheduled( 'tpsos_send_order', array( $order_id ) ) ) {
			wp_schedule_single_event( time() + 10, 'tpsos_send_order', array( $order_id ) );
			if ( function_exists( 'spawn_cron' ) ) {
				spawn_cron();
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Send (or validate, in testing) one order. $force = admin pressed "Send now".
	 */
	public static function send( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$env = TPSOS_Settings::env();
		$row = TPSOS_DB::find( $order_id, $env );

		if ( ! $row ) {
			TPSOS_DB::save( $order_id, $env, array( 'ext_order_number' => self::ext_number( $order ), 'sync_status' => 'pending' ) );
			$row = TPSOS_DB::find( $order_id, $env );
		}

		if ( ! $force && ! self::is_paid( $order ) ) {
			TPSOS_DB::update( $row->id, array( 'sync_status' => 'awaiting_payment' ) );
			return;
		}

		$claimable = array( 'pending', 'retry', 'awaiting_payment' );
		if ( $force ) {
			$claimable[] = 'failed';
			if ( 'testing' === $env ) {
				$claimable[] = 'validated';
			}
		}
		if ( ! TPSOS_DB::claim( $row->id, $claimable ) ) {
			return;
		}

		$validate = 'live' !== $env;
		$payload  = self::build_payload( $order, $validate );

		if ( is_wp_error( $payload ) ) {
			self::fail( $row->id, $order, $payload->get_error_message(), $env );
			return;
		}

		TPSOS_DB::update(
			$row->id,
			array(
				'request_json'     => wp_json_encode( $payload ),
				'ext_order_number' => $payload['order_number'],
				'attempts'         => (int) $row->attempts + 1,
			)
		);

		$res = TPSOS_API::create_order( $payload, $env );
		self::handle_response( $row->id, $order, $res, $validate, $env );
	}

	/**
	 * Build the createOrder body. Everything about the TPS request shape lives here,
	 * so adjusting it to the OpenAPI spec is a one-place change (or use the filter).
	 */
	public static function build_payload( $order, $validate ) {
		$use_shipping = '' !== trim( $order->get_shipping_address_1() );
		$g            = function ( $field ) use ( $order, $use_shipping ) {
			$method = ( $use_shipping ? 'get_shipping_' : 'get_billing_' ) . $field;
			return trim( (string) $order->$method() );
		};

		$country = strtoupper( $g( 'country' ) );
		if ( 'yes' === TPSOS_Settings::get( 'us_only' ) && '' !== $country && 'US' !== $country ) {
			return new WP_Error( 'tpsos_country', 'TPS ships to US addresses only (order country: ' . $country . ').' );
		}

		$name = trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) );
		if ( '' === $name || '' === $g( 'address_1' ) || '' === $g( 'city' ) || '' === $g( 'state' ) || '' === $g( 'postcode' ) ) {
			return new WP_Error( 'tpsos_address', 'The shipping address is incomplete (name, street, city, state and ZIP are required).' );
		}

		// Items by SKU (SKUs already come from TPS).
		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( $product && ! $product->needs_shipping() ) {
				continue;
			}
			$qty = (int) $item->get_quantity() + (int) $order->get_qty_refunded_for_item( $item_id );
			if ( $qty <= 0 ) {
				continue;
			}
			$sku = $product ? trim( (string) $product->get_sku() ) : '';
			if ( '' === $sku && $product && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				$sku    = $parent ? trim( (string) $parent->get_sku() ) : '';
			}
			if ( '' === $sku ) {
				return new WP_Error( 'tpsos_sku', 'Line item "' . $item->get_name() . '" has no SKU, so it cannot be ordered from TPS.' );
			}
			$items[ $sku ] = ( isset( $items[ $sku ] ) ? $items[ $sku ] : 0 ) + $qty;
		}
		if ( ! $items ) {
			return new WP_Error( 'tpsos_items', 'The order has no shippable items.' );
		}

		$ship_to = array(
			'name'     => $name,
			'address1' => $g( 'address_1' ),
			'address2' => $g( 'address_2' ),
			'city'     => $g( 'city' ),
			'state'    => strtoupper( $g( 'state' ) ),
			'zip'      => $g( 'postcode' ),
		);
		if ( '' !== $g( 'company' ) ) {
			$ship_to['company'] = $g( 'company' );
		}

		// Phone: only when the customer actually provided one.
		$phone = $use_shipping ? trim( (string) $order->get_shipping_phone() ) : '';
		if ( '' === $phone ) {
			$phone = trim( (string) $order->get_billing_phone() );
		}
		if ( '' !== $phone ) {
			$ship_to['phone'] = $phone;
		}

		if ( 'yes' === TPSOS_Settings::get( 'send_email' ) && is_email( $order->get_billing_email() ) ) {
			$ship_to['email'] = $order->get_billing_email();
		}

		$lines = array();
		foreach ( $items as $sku => $qty ) {
			$lines[] = array( 'sku' => (string) $sku, 'quantity' => (int) $qty );
		}

		$payload = array(
			'order_number'   => self::ext_number( $order ),
			'validate_only'  => (bool) $validate,
			'address_policy' => TPSOS_Settings::get( 'address_policy' ),
			'ship_to'        => $ship_to,
			'items'          => $lines,
		);

		return apply_filters( 'tpsos_create_order_payload', $payload, $order, $validate );
	}

	private static function handle_response( $row_id, $order, $res, $validate, $env ) {
		$code = (int) $res['code'];
		$type = (string) $res['type'];
		$resp = wp_json_encode(
			array(
				'http'       => $code,
				'request_id' => $res['request_id'],
				'body'       => null !== $res['data'] ? $res['data'] : $res['raw'],
			)
		);

		if ( $res['ok'] ) {
			$data    = $res['data'];
			$update  = array(
				'response_json'    => $resp,
				'last_error'       => '',
				'subtotal'         => self::money( self::pick( $data, array( 'subtotal' ) ) ),
				'shipping_cost'    => self::money( self::pick( $data, array( 'shipping', 'shipping_cost' ) ) ),
				'order_total'      => self::money( self::pick( $data, array( 'total', 'order_total' ) ) ),
				'tps_order_number' => self::pick( $data, array( 'tps_order_number', 'TpsOrderNumber', 'order_id' ) ),
				'batch_number'     => self::pick( $data, array( 'batch_number', 'batch', 'BatchNum' ) ),
			);
			$quote = '';
			if ( null !== $update['order_total'] ) {
				$quote = sprintf( ' Subtotal %s, shipping %s, total %s.', self::fmt( $update['subtotal'] ), self::fmt( $update['shipping_cost'] ), self::fmt( $update['order_total'] ) );
			}

			if ( $validate ) {
				$update['sync_status'] = 'validated';
				TPSOS_DB::update( $row_id, $update );
				$order->add_order_note( 'TPS Orders Sync (TESTING): order validated by TPS, nothing was placed or charged.' . $quote );
				return;
			}

			if ( 202 === $code ) {
				$update['sync_status'] = 'unconfirmed';
				TPSOS_DB::update( $row_id, $update );
				$order->add_order_note( 'TPS Orders Sync: order exists at TPS but the charge was NOT confirmed (HTTP 202). Do not resubmit; check the order in your TPS account.' );
				TPSOS_Emails::alert( 'TPS order needs attention: #' . $order->get_order_number(), 'TPS accepted the order but could not confirm the card charge (HTTP 202). The order was not resubmitted. Please check getOrders / your TPS account for the order status.', $order );
				return;
			}

			$update['sync_status'] = 'sent';
			TPSOS_DB::update( $row_id, $update );
			$order->add_order_note( 'TPS Orders Sync: order placed with TPS' . ( $update['tps_order_number'] ? ' (TPS #' . $update['tps_order_number'] . ')' : '' ) . '.' . $quote );
			return;
		}

		TPSOS_DB::update( $row_id, array( 'response_json' => $resp ) );
		$msg = trim( $type . ': ' . $res['error'], ': ' );

		// Live only: the order number already exists at TPS -> treat as placed, reconcile via getOrders.
		if ( ! $validate && 409 === $code && 'account_busy' !== $type ) {
			$remote = self::lookup_remote( $order, $env );
			$upd    = array(
				'sync_status' => 'sent',
				'last_error'  => '',
			);
			if ( $remote ) {
				$upd['tps_order_number'] = self::pick( $remote, array( 'TpsOrderNumber', 'tps_order_number', 'OrderNumber', 'OrderNum' ) );
				$upd['batch_number']     = self::pick( $remote, array( 'BatchNum', 'batch_number' ) );
				$upd['tps_status']       = self::pick( $remote, array( 'Status', 'OrderStatus' ) );
			}
			TPSOS_DB::update( $row_id, $upd );
			$order->add_order_note( 'TPS Orders Sync: TPS reports this order number was already placed; marked as sent (no duplicate created).' );
			return;
		}

		// Temporary problems -> retry the same order_number (idempotent at TPS).
		if ( ( 0 === $code && 'no_token' !== $type ) || 'account_busy' === $type || 429 === $code || $code >= 500 ) {
			self::retry( $row_id, $order, $res, $msg );
			return;
		}

		self::fail( $row_id, $order, self::explain( $code, $type, $msg ), $env );
	}

	private static function explain( $code, $type, $msg ) {
		$hint = '';
		if ( 'ordering_not_enabled' === $type ) {
			$hint = ' Ask alex@perfumesworld.net to enable ordering on your API token.';
		} elseif ( 402 === $code ) {
			$hint = ' The default card on your TPS account was declined: update it on tps-wholesale.com, then press "Send to TPS" on the order.';
		} elseif ( 401 === $code ) {
			$hint = ' Check the API token in TPS Orders Sync > Settings.';
		}
		return 'TPS rejected the order (HTTP ' . $code . '): ' . $msg . '.' . $hint;
	}

	private static function retry( $row_id, $order, $res, $msg ) {
		$row      = TPSOS_DB::find_by_id( $row_id );
		$attempts = (int) $row->attempts;
		$limit    = ( 'order_limit_reached' === $res['type'] );

		if ( ! $limit && $attempts >= 6 ) {
			self::fail( $row_id, $order, 'Gave up after ' . $attempts . ' attempts. Last error: ' . $msg, TPSOS_Settings::env() );
			return;
		}

		if ( $limit ) {
			$delay = min( max( (int) $res['retry_after'], 300 ), DAY_IN_SECONDS + HOUR_IN_SECONDS );
		} else {
			$delay = min( max( (int) $res['retry_after'], 60 * (int) pow( 2, max( 0, $attempts - 1 ) ) ), HOUR_IN_SECONDS );
		}

		TPSOS_DB::update( $row_id, array( 'sync_status' => 'retry', 'last_error' => $msg ) );
		wp_schedule_single_event( time() + $delay, 'tpsos_send_order', array( (int) $order->get_id() ) );

		if ( 1 === $attempts || $limit ) {
			$order->add_order_note( 'TPS Orders Sync: temporary problem (' . $msg . '). Will retry automatically in ~' . max( 1, (int) round( $delay / 60 ) ) . ' min.' );
		}
	}

	private static function fail( $row_id, $order, $message, $env ) {
		TPSOS_DB::update( $row_id, array( 'sync_status' => 'failed', 'last_error' => $message ) );
		$label = ( 'live' === $env ) ? '' : ' (TESTING)';
		$order->add_order_note( 'TPS Orders Sync' . $label . ' FAILED: ' . $message );
		if ( 'live' === $env ) {
			TPSOS_Emails::alert( 'TPS order FAILED: #' . $order->get_order_number(), $message . "\n\nThe order stays in Processing. Fix the problem and press \"Send to TPS\" on the order screen.", $order );
		}
	}

	/** Find our order in getOrders (used when createOrder answers 409). */
	private static function lookup_remote( $order, $env ) {
		$created = $order->get_date_created();
		$ts      = $created ? $created->getTimestamp() : time();
		$res     = TPSOS_API::get_orders(
			array(
				'sd'    => gmdate( 'Y-m-d', $ts - DAY_IN_SECONDS ),
				'ed'    => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
				'limit' => 250,
				'sort'  => 'desc',
			),
			$env
		);
		if ( ! $res['ok'] ) {
			return null;
		}
		$want = strtolower( self::ext_number( $order ) );
		foreach ( self::extract_list( $res['data'] ) as $o ) {
			if ( strtolower( self::pick( $o, array( 'ExtOrderNum', 'ext_order_num', 'ExtOrderNumber' ) ) ) === $want ) {
				return $o;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Polling (order updates)                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Check TPS for shipment updates on open live orders.
	 * Returns a summary array for the admin UI.
	 */
	public static function poll( $only_row_id = null ) {
		$summary = array( 'checked' => 0, 'shipped' => 0, 'message' => '' );

		if ( ! $only_row_id && 'yes' !== TPSOS_Settings::get( 'poll_enabled' ) ) {
			return $summary;
		}

		$rows = TPSOS_DB::open_rows( 'live', $only_row_id ? (int) $only_row_id : null );
		if ( ! $rows ) {
			$summary['message'] = 'No open live orders to check.';
			return $summary;
		}

		$oldest = time();
		foreach ( $rows as $r ) {
			$oldest = min( $oldest, strtotime( $r->created_at . ' UTC' ) );
		}

		$remote = array();
		$page   = 1;
		do {
			$res = TPSOS_API::get_orders(
				array(
					'sd'    => gmdate( 'Y-m-d', $oldest - DAY_IN_SECONDS ),
					'ed'    => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
					'limit' => 250,
					'page'  => $page,
					'sort'  => 'asc',
				),
				'live'
			);
			if ( ! $res['ok'] ) {
				$summary['message'] = 'getOrders failed: ' . $res['error'];
				update_option( 'tpsos_last_poll', array( 'time' => time(), 'ok' => false, 'message' => $summary['message'] ), false );
				return $summary;
			}
			$list = self::extract_list( $res['data'] );
			foreach ( $list as $o ) {
				$k = strtolower( self::pick( $o, array( 'ExtOrderNum', 'ext_order_num', 'ExtOrderNumber' ) ) );
				if ( '' !== $k ) {
					$remote[ $k ] = $o;
				}
			}
			$page++;
		} while ( count( $list ) >= 250 && $page <= 20 );

		foreach ( $rows as $row ) {
			$key = strtolower( (string) $row->ext_order_number );
			if ( ! isset( $remote[ $key ] ) ) {
				continue;
			}
			$summary['checked']++;
			if ( self::process_remote( $row, $remote[ $key ] ) ) {
				$summary['shipped']++;
			}
		}

		$summary['message'] = sprintf( 'Checked %d order(s), %d newly shipped.', $summary['checked'], $summary['shipped'] );
		update_option( 'tpsos_last_poll', array( 'time' => time(), 'ok' => true, 'message' => $summary['message'] ), false );
		return $summary;
	}

	public static function status_is_shipped( $status ) {
		$s = strtolower( (string) $status );
		if ( '' === $s ) {
			return false;
		}
		if ( preg_match( '/awaiting|pending|ready|not\s|unship|to ship|partial|label/', $s ) ) {
			return false;
		}
		return (bool) preg_match( '/ship|dispatch|deliver|in transit|complete/', $s );
	}

	/** Returns true when the order was newly marked as shipped. */
	private static function process_remote( $row, $o ) {
		$status   = self::pick( $o, array( 'Status', 'OrderStatus', 'order_status', 'status' ) );
		$tracking = self::pick( $o, array( 'TrackingNumber', 'TrackingNum', 'TrackingNo', 'Tracking', 'tracking_number' ) );
		$carrier  = self::pick( $o, array( 'Carrier', 'ShipVia', 'ShippingCarrier', 'ShipMethod', 'Courier' ) );
		$url      = self::pick( $o, array( 'TrackingUrl', 'TrackingURL', 'tracking_url' ) );

		$shipped_by_status = (bool) apply_filters( 'tpsos_is_shipped_status', self::status_is_shipped( $status ), $status, $o );

		if ( $shipped_by_status && '' === $tracking ) {
			$t = self::fetch_tracking( $row );
			if ( $t ) {
				$tracking = $t['tracking'];
				$carrier  = $carrier ? $carrier : $t['carrier'];
				$url      = $url ? $url : $t['url'];
			}
		}

		$base = array( 'tps_status' => $status );
		$num  = self::pick( $o, array( 'TpsOrderNumber', 'tps_order_number', 'OrderNumber', 'OrderNum' ) );
		$batch = self::pick( $o, array( 'BatchNum', 'batch_number', 'BatchNumber' ) );
		if ( $num && ! $row->tps_order_number ) {
			$base['tps_order_number'] = $num;
		}
		if ( $batch && ! $row->batch_number ) {
			$base['batch_number'] = $batch;
		}

		$order = wc_get_order( $row->wc_order_id );

		if ( $shipped_by_status || '' !== $tracking ) {
			$base['sync_status']     = 'shipped';
			$base['tracking_number'] = $tracking;
			$base['carrier']         = $carrier;
			$base['tracking_url']    = $url;
			$base['shipped_at']      = TPSOS_DB::now();
			$base['last_error']      = '';
			$base['response_json']   = wp_json_encode( array( 'poll' => $o ) );
			TPSOS_DB::update( $row->id, $base );
			if ( $order ) {
				self::on_shipped( $order, TPSOS_DB::find_by_id( $row->id ) );
			}
			return true;
		}

		if ( preg_match( '/cancel|declin|void/i', $status ) && 'cancelled' !== $row->sync_status ) {
			$base['sync_status']   = 'cancelled';
			$base['last_error']    = 'TPS status: ' . $status;
			$base['response_json'] = wp_json_encode( array( 'poll' => $o ) );
			TPSOS_DB::update( $row->id, $base );
			if ( $order ) {
				$order->add_order_note( 'TPS Orders Sync: TPS status is "' . $status . '". The order will not ship; please review it.' );
				TPSOS_Emails::alert( 'TPS order cancelled/declined: #' . $order->get_order_number(), 'TPS reports the status "' . $status . '" for this order. The Woo order was left untouched.', $order );
			}
			return false;
		}

		TPSOS_DB::update( $row->id, $base );
		return false;
	}

	private static function fetch_tracking( $row ) {
		$params = apply_filters( 'tpsos_get_tracking_params', array( 'ExtOrderNum' => $row->ext_order_number ), $row );
		$res    = TPSOS_API::get_tracking( $params, 'live' );
		if ( ! $res['ok'] ) {
			return null;
		}
		$list = self::extract_list( $res['data'] );
		$src  = $list ? $list[0] : $res['data'];
		$t    = self::pick( $src, array( 'TrackingNumber', 'TrackingNum', 'TrackingNo', 'Tracking', 'tracking_number' ) );
		if ( '' === $t ) {
			return null;
		}
		return array(
			'tracking' => $t,
			'carrier'  => self::pick( $src, array( 'Carrier', 'ShipVia', 'ShippingCarrier', 'Courier' ) ),
			'url'      => self::pick( $src, array( 'TrackingUrl', 'TrackingURL', 'tracking_url' ) ),
		);
	}

	/** Shipment confirmed: update Woo, store meta, send emails. */
	private static function on_shipped( $order, $row ) {
		$order->update_meta_data( '_tpsos_tps_order_number', $row->tps_order_number );
		$order->update_meta_data( '_tpsos_tracking_number', $row->tracking_number );
		$order->update_meta_data( '_tpsos_carrier', $row->carrier );
		$order->update_meta_data( '_tpsos_tracking_url', TPSOS_Carriers::url_for_row( $row ) );
		$order->save();

		$note = 'TPS Orders Sync: TPS shipped this order.';
		if ( $row->tracking_number ) {
			$note .= ' Tracking: ' . $row->tracking_number . ( $row->carrier ? ' (' . $row->carrier . ')' : '' ) . '.';
		}
		$order->add_order_note( $note );

		$target = (string) TPSOS_Settings::get( 'shipped_status' );
		if ( '' !== $target && ! $order->has_status( array( $target, 'cancelled', 'refunded', 'failed' ) ) ) {
			self::$suppress_completed_email = true;
			$order->update_status( $target, 'TPS Orders Sync: shipment confirmed by TPS. ' );
			self::$suppress_completed_email = false;
		}

		TPSOS_Emails::shipped( $order, $row );
	}

	/** Avoid a duplicate customer email: our own shipped email replaces Woo's "completed" email for this transition. */
	public static function maybe_suppress_completed_email( $enabled, $order ) {
		if ( self::$suppress_completed_email && 'yes' === TPSOS_Settings::get( 'notify_customer' ) ) {
			return false;
		}
		return $enabled;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/** First non-empty scalar value for any of $keys (case-insensitive, searched recursively, in priority order). */
	public static function pick( $arr, $keys ) {
		if ( ! is_array( $arr ) ) {
			return '';
		}
		foreach ( $keys as $k ) {
			$v = self::find_key( $arr, strtolower( $k ) );
			if ( null !== $v && '' !== trim( (string) $v ) ) {
				return trim( (string) $v );
			}
		}
		return '';
	}

	private static function find_key( $arr, $k ) {
		foreach ( $arr as $key => $v ) {
			if ( is_string( $key ) && strtolower( $key ) === $k && is_scalar( $v ) ) {
				return $v;
			}
		}
		foreach ( $arr as $v ) {
			if ( is_array( $v ) ) {
				$r = self::find_key( $v, $k );
				if ( null !== $r ) {
					return $r;
				}
			}
		}
		return null;
	}

	private static function is_list( $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	public static function extract_list( $d ) {
		if ( ! is_array( $d ) ) {
			return array();
		}
		if ( self::is_list( $d ) ) {
			return array_values( array_filter( $d, 'is_array' ) );
		}
		foreach ( array( 'data', 'orders', 'results', 'items', 'tracking' ) as $k ) {
			if ( isset( $d[ $k ] ) && is_array( $d[ $k ] ) ) {
				$r = self::extract_list( $d[ $k ] );
				if ( $r ) {
					return $r;
				}
			}
		}
		return array();
	}

	/** Parse "1,250.50" / 12.5 / "12.50" into a float, or null. */
	public static function money( $v ) {
		if ( '' === $v || null === $v ) {
			return null;
		}
		return (float) str_replace( ',', '', (string) $v );
	}

	public static function fmt( $v ) {
		return null === $v ? 'n/a' : '$' . number_format( (float) $v, 2 );
	}
}
