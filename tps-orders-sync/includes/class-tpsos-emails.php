<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin + customer notifications (wrapped in the WooCommerce email template).
 */
class TPSOS_Emails {

	private static function deliver( $to, $subject, $heading, $body_html ) {
		if ( ! $to || ! function_exists( 'WC' ) ) {
			return;
		}
		$mailer  = WC()->mailer();
		$message = $mailer->wrap_message( $heading, $body_html );
		$mailer->send( $to, $subject, $message );
	}

	/** Tracking/shipment block (also used on My Account and inside Woo emails). */
	public static function tracking_block( $row, $plain = false ) {
		$link = TPSOS_Carriers::url_for_row( $row );

		if ( $plain ) {
			$out  = "\n" . strtoupper( 'Shipment details' ) . "\n";
			$out .= 'Status: Shipped' . "\n";
			if ( $row->tracking_number ) {
				$out .= 'Tracking number: ' . $row->tracking_number . "\n";
			}
			if ( $row->carrier ) {
				$out .= 'Carrier: ' . $row->carrier . "\n";
			}
			if ( $link ) {
				$out .= 'Track: ' . $link . "\n";
			}
			return $out;
		}

		$html  = '<div style="margin:0 0 24px;padding:16px;border:1px solid #e5e5e5;border-radius:4px;">';
		$html .= '<h2 style="margin:0 0 12px;font-size:18px;">Shipment details</h2>';
		$html .= '<table cellspacing="0" cellpadding="6" style="width:100%;border:0;">';
		$html .= '<tr><th align="left" style="width:40%;">Status</th><td>Shipped</td></tr>';
		if ( $row->tracking_number ) {
			$html .= '<tr><th align="left">Tracking number</th><td>' . esc_html( $row->tracking_number ) . '</td></tr>';
		}
		if ( $row->carrier ) {
			$html .= '<tr><th align="left">Carrier</th><td>' . esc_html( $row->carrier ) . '</td></tr>';
		}
		$html .= '</table>';
		if ( $link ) {
			$html .= '<p style="margin:12px 0 0;"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:10px 18px;background:#111;color:#fff;text-decoration:none;border-radius:4px;">Track your package</a></p>';
			$html .= '<p style="margin:8px 0 0;font-size:12px;word-break:break-all;">' . esc_html( $link ) . '</p>';
		}
		$html .= '</div>';
		return $html;
	}

	/** Shipped: email customer + admin with tracking ID and URL. */
	public static function shipped( $order, $row ) {
		$num = $order->get_order_number();

		if ( 'yes' === TPSOS_Settings::get( 'notify_customer' ) && is_email( $order->get_billing_email() ) ) {
			$body  = '<p>Hi ' . esc_html( $order->get_billing_first_name() ) . ',</p>';
			$body .= '<p>Good news: your order #' . esc_html( $num ) . ' is on its way.</p>';
			$body .= self::tracking_block( $row );

			$page = (int) TPSOS_Settings::get( 'tracking_page_id' );
			if ( $page && get_permalink( $page ) ) {
				$body .= '<p>You can also track it any time at <a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a>.</p>';
			}
			self::deliver(
				$order->get_billing_email(),
				sprintf( 'Your order #%s has shipped', $num ),
				'Your order has shipped',
				$body
			);
		}

		if ( 'yes' === TPSOS_Settings::get( 'notify_admin' ) ) {
			$body  = '<p>TPS has shipped order <a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $num ) . '</a>.</p>';
			$body .= '<p>TPS order #: ' . esc_html( $row->tps_order_number ? $row->tps_order_number : 'n/a' ) . '<br>Customer: ' . esc_html( $order->get_formatted_billing_full_name() ) . '</p>';
			$body .= self::tracking_block( $row );
			self::deliver(
				TPSOS_Settings::admin_email(),
				sprintf( '[TPS] Order #%s shipped', $num ),
				'TPS order shipped',
				$body
			);
		}
	}

	/** Problems that need a human (failed / unconfirmed / declined). */
	public static function alert( $subject, $text, $order = null ) {
		if ( 'yes' !== TPSOS_Settings::get( 'notify_admin' ) ) {
			return;
		}
		$body = '<p>' . nl2br( esc_html( $text ) ) . '</p>';
		if ( $order ) {
			$body .= '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">Open order #' . esc_html( $order->get_order_number() ) . '</a></p>';
		}
		self::deliver( TPSOS_Settings::admin_email(), '[TPS Orders Sync] ' . $subject, 'TPS Orders Sync', $body );
	}
}
