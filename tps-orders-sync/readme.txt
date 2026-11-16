=== TPS Orders Sync ===
Contributors: usamariaz
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Automatically pushes paid WooCommerce dropship orders to The Perfume Spot (perfumesw.com DropShip API) and syncs tracking back.

== Description ==

* Fully automatic: when a Woo order is paid it is sent to TPS (createOrder). Unpaid / on-hold orders are never sent.
* Testing / Live environment. Testing uses validate_only (nothing is placed or charged).
* Idempotent: the Woo order number is the TPS order_number; timeouts and busy-account errors are retried safely.
* Polls getOrders every 15 minutes. When TPS ships, the Woo order moves to Completed (configurable), and the admin and customer get an email with tracking ID and URL.
* TPS details in the Woo orders list, the order screen, a TPS Orders Sync table, customer emails and My Account.
* [tps_track] shortcode: customer enters a tracking number and is redirected to the carrier's tracking site.

== Installation ==

1. Upload the zip via Plugins > Add New > Upload Plugin and activate (WooCommerce required).
2. Go to TPS Orders Sync > Settings, paste the API token, leave the environment on Testing and press "Test API connection".
3. Place a test order: check the TPS Orders Sync table for "Test validated" and the shipping quote.
4. Switch to Live (TPS must have enabled ordering on your token).

== Developer filters ==

* tpsos_create_order_payload ( $payload, $order, $validate )
* tpsos_order_is_paid ( $paid, $order )
* tpsos_should_sync_order ( $bool, $order )
* tpsos_is_shipped_status ( $bool, $status, $remote_order )
* tpsos_get_tracking_params ( $params, $row )
* tpsos_tracking_url ( $url, $tracking, $carrier, $key )
