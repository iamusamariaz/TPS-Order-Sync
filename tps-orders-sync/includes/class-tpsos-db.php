<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's own orders table: one row per Woo order per environment.
 */
class TPSOS_DB {

	const VERSION = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tps_orders';
	}

	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_order_id bigint(20) unsigned NOT NULL,
			environment varchar(10) NOT NULL DEFAULT 'testing',
			ext_order_number varchar(100) NOT NULL DEFAULT '',
			tps_order_number varchar(100) NOT NULL DEFAULT '',
			batch_number varchar(100) NOT NULL DEFAULT '',
			sync_status varchar(20) NOT NULL DEFAULT 'pending',
			tps_status varchar(100) NOT NULL DEFAULT '',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			subtotal decimal(10,2) DEFAULT NULL,
			shipping_cost decimal(10,2) DEFAULT NULL,
			order_total decimal(10,2) DEFAULT NULL,
			carrier varchar(100) NOT NULL DEFAULT '',
			tracking_number varchar(150) NOT NULL DEFAULT '',
			tracking_url text NULL,
			shipped_at datetime DEFAULT NULL,
			last_error text NULL,
			request_json longtext NULL,
			response_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_env (wc_order_id,environment),
			KEY sync_status (sync_status),
			KEY tracking_number (tracking_number),
			KEY ext_order_number (ext_order_number)
		) $charset;";

		dbDelta( $sql );
		update_option( 'tpsos_db_version', self::VERSION );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'tpsos_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function find( $wc_order_id, $env ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE wc_order_id = %d AND environment = %s", $wc_order_id, $env ) );
	}

	public static function find_by_id( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
	}

	/** Row to show for an order: the live row when present, otherwise the newest. */
	public static function latest_for_order( $wc_order_id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE wc_order_id = %d ORDER BY (environment = 'live') DESC, id DESC LIMIT 1", $wc_order_id ) );
	}

	/** Live row only (what customers may see). */
	public static function live_for_order( $wc_order_id ) {
		return self::find( $wc_order_id, 'live' );
	}

	public static function find_by_tracking( $tracking ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE tracking_number = %s AND environment = 'live' ORDER BY id DESC LIMIT 1", $tracking ) );
	}

	/** Insert or update a row for (order, env). Returns row id. */
	public static function save( $wc_order_id, $env, $data ) {
		global $wpdb;
		$t   = self::table();
		$row = self::find( $wc_order_id, $env );
		$data['updated_at'] = self::now();

		if ( $row ) {
			$wpdb->update( $t, $data, array( 'id' => $row->id ) );
			return (int) $row->id;
		}

		$data = array_merge(
			array(
				'wc_order_id' => $wc_order_id,
				'environment' => $env,
				'created_at'  => self::now(),
			),
			$data
		);
		$wpdb->insert( $t, $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = self::now();
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Atomically move a row to "sending". Stale "sending" rows (>10 min) can be reclaimed.
	 * Returns true when this process owns the send.
	 */
	public static function claim( $id, $from_statuses ) {
		global $wpdb;
		$t     = self::table();
		$in    = "'" . implode( "','", array_map( 'esc_sql', $from_statuses ) ) . "'";
		$stale = gmdate( 'Y-m-d H:i:s', time() - 600 );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE $t SET sync_status = 'sending', updated_at = %s WHERE id = %d AND ( sync_status IN ($in) OR ( sync_status = 'sending' AND updated_at < %s ) )",
				self::now(),
				$id,
				$stale
			)
		);
		return 1 === (int) $n;
	}

	/** Live rows still waiting for TPS to ship. */
	public static function open_rows( $env, $only_id = null ) {
		global $wpdb;
		$t = self::table();
		if ( $only_id ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND environment = %s", $only_id, $env ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE environment = %s AND sync_status IN ('sent','unconfirmed') ORDER BY id ASC LIMIT 500", $env ) );
	}

	public static function query( $args ) {
		global $wpdb;
		$t      = self::table();
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'sync_status = %s';
			$values[] = $args['status'];
		}
		if ( ! empty( $args['env'] ) ) {
			$where[]  = 'environment = %s';
			$values[] = $args['env'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( ext_order_number LIKE %s OR tps_order_number LIKE %s OR tracking_number LIKE %s OR wc_order_id = %d )';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = (int) $args['search'];
		}

		$where_sql = implode( ' AND ', $where );
		$per       = max( 1, (int) $args['per_page'] );
		$offset    = max( 0, ( (int) $args['paged'] - 1 ) * $per );

		$count_sql = "SELECT COUNT(*) FROM $t WHERE $where_sql";
		$rows_sql  = "SELECT * FROM $t WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";

		if ( $values ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $values, array( $per, $offset ) ) ) );

		return array( $rows, $total );
	}

	public static function delete_env( $env ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'environment' => $env ) );
	}

	public static function counts() {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT sync_status, COUNT(*) AS c FROM $t GROUP BY sync_status" );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->sync_status ] = (int) $r->c;
		}
		return $out;
	}
}
