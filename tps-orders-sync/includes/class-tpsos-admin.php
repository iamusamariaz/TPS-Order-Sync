<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI: settings, TPS Orders Sync table, Woo order metabox + list column, action handlers.
 */
class TPSOS_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_tpsos_group', function () {
			return 'manage_woocommerce';
		} );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );

		// Woo order screen (HPOS + legacy).
		add_action( 'add_meta_boxes', array( __CLASS__, 'metabox' ) );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'list_column_hpos' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'list_column_legacy' ), 10, 2 );

		// Actions.
		add_action( 'admin_post_tpsos_send', array( __CLASS__, 'handle_send' ) );
		add_action( 'admin_post_tpsos_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_tpsos_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_tpsos_poll', array( __CLASS__, 'handle_poll' ) );
		add_action( 'admin_post_tpsos_clear_test', array( __CLASS__, 'handle_clear_test' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Labels                                                              */
	/* ------------------------------------------------------------------ */

	public static function statuses() {
		return array(
			'awaiting_payment' => array( 'Awaiting payment', '#8c8f94' ),
			'pending'          => array( 'Queued', '#996800' ),
			'sending'          => array( 'Sending...', '#996800' ),
			'retry'            => array( 'Retrying', '#b26200' ),
			'validated'        => array( 'Test validated', '#2271b1' ),
			'sent'             => array( 'Sent to TPS', '#2271b1' ),
			'unconfirmed'      => array( 'Sent, charge unconfirmed', '#b26200' ),
			'shipped'          => array( 'Shipped', '#007017' ),
			'failed'           => array( 'Failed', '#b32d2e' ),
			'cancelled'        => array( 'Cancelled / declined', '#b32d2e' ),
		);
	}

	public static function badge( $status ) {
		$all = self::statuses();
		$s   = isset( $all[ $status ] ) ? $all[ $status ] : array( $status, '#50575e' );
		return '<span class="tpsos-badge" style="background:' . esc_attr( $s[1] ) . '">' . esc_html( $s[0] ) . '</span>';
	}

	private static function local_time( $utc ) {
		if ( ! $utc ) {
			return '';
		}
		return esc_html( get_date_from_gmt( $utc, 'M j, Y H:i' ) );
	}

	public static function styles() {
		echo '<style>.tpsos-badge{display:inline-block;padding:2px 8px;border-radius:10px;color:#fff;font-size:11px;font-weight:600;white-space:nowrap}.tpsos-env{padding:10px 14px;border-left:4px solid;margin:12px 0;background:#fff}.tpsos-env.live{border-color:#b32d2e}.tpsos-env.testing{border-color:#dba617}.tpsos-card{background:#fff;border:1px solid #c3c4c7;padding:4px 18px 14px;margin:16px 0;max-width:900px}.tpsos-json{max-height:220px;overflow:auto;background:#f6f7f7;padding:8px;font-size:11px;white-space:pre-wrap;word-break:break-all}.tpsos-err{color:#b32d2e}</style>';
	}

	private static function banner() {
		if ( TPSOS_Settings::is_live() ) {
			echo '<div class="tpsos-env live"><strong>LIVE mode.</strong> Paid orders are placed with TPS and the default card on your TPS account is charged.</div>';
		} else {
			echo '<div class="tpsos-env testing"><strong>TESTING mode.</strong> Orders are only validated with TPS (<code>validate_only</code>): nothing is placed, charged or shipped, and your Woo orders are not changed.</div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Menu + pages                                                        */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_menu_page( 'TPS Orders Sync', 'TPS Orders Sync', 'manage_woocommerce', 'tpsos-orders', array( __CLASS__, 'page_orders' ), 'dashicons-update', 56 );
		add_submenu_page( 'tpsos-orders', 'TPS Orders', 'Orders', 'manage_woocommerce', 'tpsos-orders', array( __CLASS__, 'page_orders' ) );
		add_submenu_page( 'tpsos-orders', 'TPS Orders Sync Settings', 'Settings', 'manage_woocommerce', 'tpsos-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function register_settings() {
		register_setting(
			'tpsos_group',
			TPSOS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'TPSOS_Settings', 'sanitize' ),
			)
		);
	}

	private static function action_url( $action, $args = array(), $nonce_key = '' ) {
		$url = add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, $nonce_key ? $nonce_key : $action );
	}

	public static function page_orders() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		list( $rows, $total ) = TPSOS_DB::query( array( 'status' => $status, 'search' => $search, 'paged' => $paged, 'per_page' => 20 ) );
		$counts = TPSOS_DB::counts();
		$last   = get_option( 'tpsos_last_poll' );
		$base   = admin_url( 'admin.php?page=tpsos-orders' );

		echo '<div class="wrap"><h1>TPS Orders Sync</h1>';
		self::banner();

		echo '<p>';
		echo '<a class="button" href="' . esc_url( self::action_url( 'tpsos_poll' ) ) . '">Check TPS for updates now</a> ';
		if ( is_array( $last ) ) {
			echo '<span class="description' . ( empty( $last['ok'] ) ? ' tpsos-err' : '' ) . '">Last check: ' . esc_html( human_time_diff( $last['time'] ) ) . ' ago. ' . esc_html( $last['message'] ) . '</span>';
		}
		$next = wp_next_scheduled( 'tpsos_poll' );
		if ( $next ) {
			echo ' <span class="description">Next automatic check in ' . esc_html( human_time_diff( time(), $next ) ) . '.</span>';
		}
		echo '</p>';

		echo '<ul class="subsubsub"><li><a href="' . esc_url( $base ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>All</a></li>';
		foreach ( self::statuses() as $key => $def ) {
			if ( empty( $counts[ $key ] ) ) {
				continue;
			}
			echo ' | <li><a href="' . esc_url( add_query_arg( 'status', $key, $base ) ) . '"' . ( $status === $key ? ' class="current"' : '' ) . '>' . esc_html( $def[0] ) . ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a></li>';
		}
		echo '</ul>';

		echo '<form method="get" class="search-box"><input type="hidden" name="page" value="tpsos-orders"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Order #, TPS #, tracking"> <button class="button">Search</button></form>';

		echo '<table class="wp-list-table widefat striped" style="margin-top:12px"><thead><tr>';
		foreach ( array( 'Woo order', 'Env', 'TPS order #', 'Sync status', 'TPS status', 'Tracking', 'TPS total', 'Updated', 'Actions' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $rows ) {
			echo '<tr><td colspan="9">No TPS orders yet. Paid orders appear here automatically.</td></tr>';
		}

		foreach ( $rows as $r ) {
			$order = wc_get_order( $r->wc_order_id );
			$link  = $order ? $order->get_edit_order_url() : '';
			echo '<tr>';
			echo '<td>' . ( $link ? '<a href="' . esc_url( $link ) . '"><strong>#' . esc_html( $order->get_order_number() ) . '</strong></a>' : '#' . (int) $r->wc_order_id ) . '<br><small>' . esc_html( $r->ext_order_number ) . '</small></td>';
			echo '<td>' . esc_html( ucfirst( $r->environment ) ) . '</td>';
			echo '<td>' . esc_html( $r->tps_order_number ? $r->tps_order_number : '-' ) . ( $r->batch_number ? '<br><small>Batch ' . esc_html( $r->batch_number ) . '</small>' : '' ) . '</td>';
			echo '<td>' . self::badge( $r->sync_status ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $r->last_error ) {
				echo '<br><small class="tpsos-err">' . esc_html( $r->last_error ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $r->tps_status ? $r->tps_status : '-' ) . '</td>';
			echo '<td>';
			if ( $r->tracking_number ) {
				echo '<a href="' . esc_url( TPSOS_Carriers::url_for_row( $r ) ) . '" target="_blank" rel="noopener">' . esc_html( $r->tracking_number ) . '</a>' . ( $r->carrier ? '<br><small>' . esc_html( $r->carrier ) . '</small>' : '' );
			} else {
				echo '-';
			}
			echo '</td>';
			echo '<td>' . ( null !== $r->order_total ? esc_html( '$' . number_format( (float) $r->order_total, 2 ) ) . '<br><small>ship $' . esc_html( number_format( (float) $r->shipping_cost, 2 ) ) . '</small>' : '-' ) . '</td>';
			echo '<td>' . self::local_time( $r->updated_at ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . self::row_actions( $r ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';

			if ( $r->request_json || $r->response_json ) {
				echo '<tr><td colspan="9"><details><summary>Request / response log</summary>';
				echo '<p><strong>Request</strong></p><div class="tpsos-json">' . esc_html( $r->request_json ) . '</div>';
				echo '<p><strong>Response</strong></p><div class="tpsos-json">' . esc_html( $r->response_json ) . '</div></details></td></tr>';
			}
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $total / 20 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', $base ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
		echo '</div>';
	}

	private static function row_actions( $r ) {
		$out = array();
		if ( in_array( $r->sync_status, array( 'awaiting_payment', 'failed', 'pending', 'retry', 'validated' ), true ) ) {
			$confirm = TPSOS_Settings::is_live() ? ' onclick="return confirm(\'Place this order with TPS now? The default TPS card will be charged.\');"' : '';
			$out[]   = '<a class="button button-small" href="' . esc_url( self::action_url( 'tpsos_send', array( 'order_id' => $r->wc_order_id ), 'tpsos_send_' . $r->wc_order_id ) ) . '"' . $confirm . '>' . ( TPSOS_Settings::is_live() ? 'Send to TPS' : 'Validate' ) . '</a>';
		}
		if ( 'live' === $r->environment && in_array( $r->sync_status, array( 'sent', 'unconfirmed' ), true ) ) {
			$out[] = '<a class="button button-small" href="' . esc_url( self::action_url( 'tpsos_refresh', array( 'row_id' => $r->id ), 'tpsos_refresh_' . $r->id ) ) . '">Refresh</a>';
		}
		return implode( ' ', $out );
	}

	public static function page_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s        = TPSOS_Settings::all();
		$opt      = TPSOS_Settings::OPTION;
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		?>
		<div class="wrap">
			<h1>TPS Orders Sync: Settings</h1>
			<?php self::banner(); ?>

			<form method="post" action="options.php" id="tpsos-settings-form">
				<?php settings_fields( 'tpsos_group' ); ?>

				<div class="tpsos-card">
					<h2>Environment</h2>
					<p>
						<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[environment]" value="testing" <?php checked( $s['environment'], 'testing' ); ?>> <strong>Testing</strong>: validate only. Nothing is placed or charged. Safe on the live site.</label><br>
						<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[environment]" value="live" <?php checked( $s['environment'], 'live' ); ?>> <strong>Live</strong>: place real orders and charge the default TPS card.</label>
					</p>
					<p class="description">Switching to Live does not send orders that were only validated in Testing; use "Send to TPS" on those if needed.</p>
				</div>

				<div class="tpsos-card">
					<h2>TPS API</h2>
					<table class="form-table">
						<tr><th>API base URL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[api_base]" value="<?php echo esc_attr( $s['api_base'] ); ?>"></td></tr>
						<tr><th>Live API token</th><td><input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $opt ); ?>[live_token]" placeholder="<?php echo $s['live_token'] ? esc_attr( 'Saved (ends ' . substr( $s['live_token'], -4 ) . '). Leave blank to keep.' ) : 'Paste your Bearer token'; ?>"></td></tr>
						<tr><th>Testing API token <small>(optional)</small></th><td><input type="password" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $opt ); ?>[test_token]" placeholder="<?php echo $s['test_token'] ? esc_attr( 'Saved (ends ' . substr( $s['test_token'], -4 ) . '). Leave blank to keep.' ) : 'Blank = use the live token (validate_only is always on in Testing)'; ?>">
							<?php if ( $s['test_token'] ) : ?><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[clear_test_token]" value="1"> remove saved testing token</label><?php endif; ?></td></tr>
					</table>
				</div>

				<div class="tpsos-card">
					<h2>Sending</h2>
					<table class="form-table">
						<tr><th>Address policy</th><td>
							<select name="<?php echo esc_attr( $opt ); ?>[address_policy]">
								<option value="strict" <?php selected( $s['address_policy'], 'strict' ); ?>>strict: reject if USPS changes or rejects the address</option>
								<option value="accept_suggested" <?php selected( $s['address_policy'], 'accept_suggested' ); ?>>accept_suggested: ship the USPS-standardised address</option>
								<option value="as_entered" <?php selected( $s['address_policy'], 'as_entered' ); ?>>as_entered: ship exactly as typed (at your risk)</option>
							</select></td></tr>
						<tr><th>Order number prefix</th><td><input type="text" class="small-text" name="<?php echo esc_attr( $opt ); ?>[order_prefix]" value="<?php echo esc_attr( $s['order_prefix'] ); ?>"> <span class="description">Sent to TPS as <code>order_number</code> (the idempotency key). Example: SQB-</span></td></tr>
						<tr><th>Options</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[us_only]" value="1" <?php checked( $s['us_only'], 'yes' ); ?>> Only send orders with a US shipping address</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[send_email]" value="1" <?php checked( $s['send_email'], 'yes' ); ?>> Send the customer's email address to TPS</label><br>
							<span class="description">The phone number is sent only when the customer entered one.</span>
						</td></tr>
					</table>
				</div>

				<div class="tpsos-card">
					<h2>When TPS ships</h2>
					<table class="form-table">
						<tr><th>Woo order status</th><td>
							<select name="<?php echo esc_attr( $opt ); ?>[shipped_status]">
								<option value="" <?php selected( $s['shipped_status'], '' ); ?>>Leave unchanged</option>
								<?php foreach ( $statuses as $key => $label ) : $k = preg_replace( '/^wc-/', '', $key ); ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['shipped_status'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td></tr>
						<tr><th>Notifications</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[notify_customer]" value="1" <?php checked( $s['notify_customer'], 'yes' ); ?>> Email the customer (tracking ID + URL)</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[notify_admin]" value="1" <?php checked( $s['notify_admin'], 'yes' ); ?>> Email the admin (shipments and problems)</label>
						</td></tr>
						<tr><th>Admin email</th><td><input type="email" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[admin_email]" value="<?php echo esc_attr( $s['admin_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"></td></tr>
						<tr><th>Tracking page</th><td><?php
							wp_dropdown_pages(
								array(
									'name'              => $opt . '[tracking_page_id]',
									'selected'          => (int) $s['tracking_page_id'],
									'show_option_none'  => '- None -',
									'option_none_value' => '0',
								)
							);
						?> <span class="description">The page that contains <code>[tps_track]</code>.</span></td></tr>
						<tr><th>Status checks</th><td>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[poll_enabled]" value="1" <?php checked( $s['poll_enabled'], 'yes' ); ?>> Check TPS for updates every 15 minutes</label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[debug_log]" value="1" <?php checked( $s['debug_log'], 'yes' ); ?>> Log API calls (WooCommerce &gt; Status &gt; Logs, source <code>tps-orders-sync</code>)</label>
						</td></tr>
					</table>
				</div>

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<div class="tpsos-card">
				<h2>Tools</h2>
				<p>
					<a class="button" href="<?php echo esc_url( self::action_url( 'tpsos_test' ) ); ?>">Test API connection</a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'tpsos_clear_test' ) ); ?>" onclick="return confirm('Delete all Testing records from the TPS Orders Sync table?');">Clear testing records</a>
				</p>
				<p class="description">The connection test uses one read-only <code>getOrders</code> call with the token of the current environment.</p>
			</div>
		</div>
		<script>
		document.getElementById('tpsos-settings-form').addEventListener('submit', function (e) {
			var live = this.querySelector('input[name$="[environment]"][value="live"]');
			if (live && live.checked && <?php echo TPSOS_Settings::is_live() ? 'false' : 'true'; ?> &&
				!confirm('Switch to LIVE? Paid orders will be placed with TPS and the TPS card will be charged automatically.')) {
				e.preventDefault();
			}
		});
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Woo order screen                                                    */
	/* ------------------------------------------------------------------ */

	public static function metabox() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'tpsos_box', 'TPS Orders Sync', array( __CLASS__, 'metabox_render' ), $screen, 'side', 'default' );
	}

	public static function metabox_render( $post_or_order ) {
		$order_id = ( $post_or_order instanceof WP_Post ) ? $post_or_order->ID : ( method_exists( $post_or_order, 'get_id' ) ? $post_or_order->get_id() : 0 );
		$row      = $order_id ? TPSOS_DB::latest_for_order( $order_id ) : null;

		if ( ! $row ) {
			echo '<p>Not sent to TPS yet. Paid orders are sent automatically.</p>';
			if ( $order_id ) {
				echo '<a class="button" href="' . esc_url( self::action_url( 'tpsos_send', array( 'order_id' => $order_id ), 'tpsos_send_' . $order_id ) ) . '">' . ( TPSOS_Settings::is_live() ? 'Send to TPS' : 'Validate with TPS' ) . '</a>';
			}
			return;
		}

		echo '<p>' . self::badge( $row->sync_status ) . ' <small>(' . esc_html( $row->environment ) . ')</small></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<table style="width:100%;font-size:12px">';
		$lines = array(
			'TPS order #' => $row->tps_order_number,
			'Batch'       => $row->batch_number,
			'TPS status'  => $row->tps_status,
			'Carrier'     => $row->carrier,
		);
		foreach ( $lines as $label => $val ) {
			if ( '' !== (string) $val ) {
				echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td><td>' . esc_html( $val ) . '</td></tr>';
			}
		}
		if ( null !== $row->order_total ) {
			echo '<tr><td><strong>TPS total</strong></td><td>$' . esc_html( number_format( (float) $row->order_total, 2 ) ) . ' (ship $' . esc_html( number_format( (float) $row->shipping_cost, 2 ) ) . ')</td></tr>';
		}
		if ( $row->tracking_number ) {
			echo '<tr><td><strong>Tracking</strong></td><td><a href="' . esc_url( TPSOS_Carriers::url_for_row( $row ) ) . '" target="_blank" rel="noopener">' . esc_html( $row->tracking_number ) . '</a></td></tr>';
		}
		echo '</table>';
		if ( $row->last_error ) {
			echo '<p class="tpsos-err"><small>' . esc_html( $row->last_error ) . '</small></p>';
		}
		echo '<p style="margin-bottom:0">' . self::row_actions( $row ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function list_columns( $columns ) {
		$columns['tpsos'] = 'TPS';
		return $columns;
	}

	private static function list_cell( $order_id ) {
		$row = TPSOS_DB::latest_for_order( $order_id );
		if ( ! $row ) {
			echo '&ndash;';
			return;
		}
		echo self::badge( $row->sync_status ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $row->tracking_number ) {
			echo '<br><a href="' . esc_url( TPSOS_Carriers::url_for_row( $row ) ) . '" target="_blank" rel="noopener"><small>' . esc_html( $row->tracking_number ) . '</small></a>';
		}
	}

	public static function list_column_hpos( $column, $order ) {
		if ( 'tpsos' === $column && $order ) {
			self::list_cell( is_object( $order ) ? $order->get_id() : (int) $order );
		}
	}

	public static function list_column_legacy( $column, $post_id ) {
		if ( 'tpsos' === $column ) {
			self::list_cell( (int) $post_id );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Action handlers                                                     */
	/* ------------------------------------------------------------------ */

	private static function guard( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $nonce_action );
	}

	private static function back( $msg, $type = 'success' ) {
		$to = wp_get_referer();
		if ( ! $to ) {
			$to = admin_url( 'admin.php?page=tpsos-orders' );
		}
		wp_safe_redirect( add_query_arg( array( 'tpsos_msg' => $msg, 'tpsos_type' => $type ), $to ) );
		exit;
	}

	public static function handle_send() {
		$id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'tpsos_send_' . $id );

		TPSOS_Sync::send( $id, true );
		$row = TPSOS_DB::find( $id, TPSOS_Settings::env() );
		if ( $row && in_array( $row->sync_status, array( 'sent', 'validated', 'unconfirmed' ), true ) ) {
			self::back( 'Order ' . ( 'validated' === $row->sync_status ? 'validated by TPS (testing).' : 'sent to TPS.' ) );
		}
		self::back( $row && $row->last_error ? $row->last_error : 'The order was not sent. See the order notes.', 'error' );
	}

	public static function handle_refresh() {
		$id = isset( $_GET['row_id'] ) ? absint( $_GET['row_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		self::guard( 'tpsos_refresh_' . $id );
		$sum = TPSOS_Sync::poll( $id );
		self::back( $sum['message'] ? $sum['message'] : 'Done.' );
	}

	public static function handle_poll() {
		self::guard( 'tpsos_poll' );
		$sum = TPSOS_Sync::poll( null );
		self::back( $sum['message'] ? $sum['message'] : 'Polling is disabled in settings.' );
	}

	public static function handle_test() {
		self::guard( 'tpsos_test' );
		$res = TPSOS_API::get_orders( array( 'limit' => 1 ) );
		if ( $res['ok'] ) {
			$wallet = TPSOS_Sync::pick( isset( $res['data']['meta'] ) ? $res['data']['meta'] : array(), array( 'wallet' ) );
			self::back( 'Connected to TPS (' . TPSOS_Settings::env() . ' token).' . ( '' !== $wallet ? ' Store-credit balance: $' . $wallet . '.' : '' ) );
		}
		self::back( 'Connection failed (HTTP ' . (int) $res['code'] . '): ' . $res['error'], 'error' );
	}

	public static function handle_clear_test() {
		self::guard( 'tpsos_clear_test' );
		$n = TPSOS_DB::delete_env( 'testing' );
		self::back( 'Removed ' . (int) $n . ' testing record(s).' );
	}

	public static function notices() {
		if ( empty( $_GET['tpsos_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg  = sanitize_text_field( wp_unslash( $_GET['tpsos_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$type = ( isset( $_GET['tpsos_type'] ) && 'error' === $_GET['tpsos_type'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}
}
