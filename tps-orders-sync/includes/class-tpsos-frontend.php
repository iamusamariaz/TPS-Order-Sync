<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer-facing: [tps_track] shortcode, My Account, Woo emails.
 */
class TPSOS_Frontend {

	public static function init() {
		add_shortcode( 'tps_track', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_tpsos_track', array( __CLASS__, 'handle_track' ) );
		add_action( 'admin_post_tpsos_track', array( __CLASS__, 'handle_track' ) );

		// My Account.
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'order_details' ), 20 );
		add_filter( 'woocommerce_my_account_my_orders_columns', array( __CLASS__, 'orders_columns' ) );
		add_action( 'woocommerce_my_account_my_orders_column_tps-tracking', array( __CLASS__, 'orders_column' ) );

		// Woo customer emails (processing, completed, invoice...).
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_block' ), 20, 4 );
	}

	/* ---- Tracking page ---- */

	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'button'      => 'Track',
				'placeholder' => 'Enter your tracking number',
				'label'       => 'Tracking number',
			),
			$atts,
			'tps_track'
		);

		$error = isset( $_GET['tpsos_err'] ) ? sanitize_key( wp_unslash( $_GET['tpsos_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		ob_start();
		?>
		<style>
			.tpsos-track{max-width:520px;margin:0 auto}
			.tpsos-track label{display:block;font-weight:600;margin-bottom:6px}
			.tpsos-track .tpsos-row{display:flex;gap:8px}
			.tpsos-track input[type=text]{flex:1;padding:10px 12px;border:1px solid #ccc;border-radius:4px}
			.tpsos-track button{padding:10px 22px;border:0;border-radius:4px;background:#111;color:#fff;cursor:pointer}
			.tpsos-track .tpsos-error{color:#b32d2e;margin:0 0 10px}
		</style>
		<form class="tpsos-track" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tpsos_track">
			<input type="hidden" name="tpsos_back" value="<?php echo esc_url( get_permalink() ); ?>">
			<?php if ( $error ) : ?>
				<p class="tpsos-error">Please enter a valid tracking number (letters, numbers and dashes only).</p>
			<?php endif; ?>
			<label for="tpsos_code"><?php echo esc_html( $atts['label'] ); ?></label>
			<div class="tpsos-row">
				<input type="text" id="tpsos_code" name="tracking_code" required maxlength="100" autocomplete="off" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>">
				<button type="submit"><?php echo esc_html( $atts['button'] ); ?></button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/** POST handler: validate the code and redirect to the carrier's tracking site. */
	public static function handle_track() {
		// phpcs:disable WordPress.Security.NonceVerification
		$code = isset( $_POST['tracking_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_code'] ) ) : '';
		$back = isset( $_POST['tpsos_back'] ) ? esc_url_raw( wp_unslash( $_POST['tpsos_back'] ) ) : home_url( '/' );
		// phpcs:enable

		$code = preg_replace( '/\s+/', '', $code );

		if ( ! preg_match( '/^[A-Za-z0-9\-._\/]{4,100}$/', $code ) ) {
			wp_safe_redirect( add_query_arg( 'tpsos_err', 'invalid', $back ) );
			exit;
		}

		$row = TPSOS_DB::find_by_tracking( $code );
		$url = $row ? TPSOS_Carriers::url_for_row( $row ) : TPSOS_Carriers::url( $code );

		// The URL is built only from our own templates / the TPS-provided link.
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/* ---- My Account ---- */

	private static function visible_row( $order ) {
		$row = TPSOS_DB::live_for_order( $order->get_id() );
		if ( $row && in_array( $row->sync_status, array( 'sent', 'unconfirmed', 'shipped' ), true ) ) {
			return $row;
		}
		return null;
	}

	public static function order_details( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$row = self::visible_row( $order );
		if ( ! $row ) {
			return;
		}
		if ( 'shipped' === $row->sync_status ) {
			echo '<section class="tpsos-shipment">' . TPSOS_Emails::tracking_block( $row ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		echo '<section class="tpsos-shipment"><h2>Shipment details</h2><p>Your order is being prepared for shipment. Tracking details will appear here (and be emailed to you) as soon as it ships.</p></section>';
	}

	public static function orders_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'order-actions' === $key ) {
				$new['tps-tracking'] = 'Tracking';
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['tps-tracking'] ) ) {
			$new['tps-tracking'] = 'Tracking';
		}
		return $new;
	}

	public static function orders_column( $order ) {
		$row = self::visible_row( $order );
		if ( ! $row ) {
			echo '&ndash;';
			return;
		}
		if ( 'shipped' === $row->sync_status && $row->tracking_number ) {
			$link = TPSOS_Carriers::url_for_row( $row );
			echo '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . esc_html( $row->tracking_number ) . '</a>';
			return;
		}
		echo 'shipped' === $row->sync_status ? 'Shipped' : 'Preparing';
	}

	/* ---- Woo emails ---- */

	public static function email_block( $order, $sent_to_admin, $plain_text, $email = null ) {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		$row = TPSOS_DB::live_for_order( $order->get_id() );
		if ( ! $row || 'shipped' !== $row->sync_status || '' === (string) $row->tracking_number ) {
			return;
		}
		echo TPSOS_Emails::tracking_block( $row, (bool) $plain_text ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
