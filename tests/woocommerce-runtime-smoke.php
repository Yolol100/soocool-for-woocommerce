<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WP_REST_Request;

function soocool_runtime_fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

$expected_wp = trim( (string) getenv( 'SOOCOOL_EXPECT_WP' ) );
$expected_wc = trim( (string) getenv( 'SOOCOOL_EXPECT_WC' ) );

if ( '' === $expected_wp || '' === $expected_wc ) {
	soocool_runtime_fail( 'Expected WordPress and WooCommerce versions must be provided.' );
}

if ( ! defined( 'SOOCOOL_VERSION' ) || '0.7.147' !== SOOCOOL_VERSION ) {
	soocool_runtime_fail( 'SooCool plugin version mismatch.' );
}

if ( ! defined( 'WC_VERSION' ) || $expected_wc !== WC_VERSION ) {
	soocool_runtime_fail( 'WooCommerce runtime version mismatch: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'not loaded' ) );
}

$wp_version = (string) get_bloginfo( 'version' );
if ( $expected_wp !== $wp_version ) {
	soocool_runtime_fail( 'WordPress runtime version mismatch: ' . $wp_version );
}

if ( ! class_exists( WC_Order::class ) || ! function_exists( 'wc_create_order' ) || ! function_exists( 'wc_get_order' ) ) {
	soocool_runtime_fail( 'WooCommerce order CRUD is unavailable.' );
}

if ( ! class_exists( OrderUtil::class ) || ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
	soocool_runtime_fail( 'HPOS must be enabled for this compatibility smoke test.' );
}

if ( ! function_exists( 'as_schedule_single_action' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
	soocool_runtime_fail( 'WooCommerce Action Scheduler runtime is unavailable.' );
}

if ( 0 === did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}

$server = rest_get_server();
$routes = $server->get_routes();
if ( ! isset( $routes['/soocool/v1/orders/(?P<id>\\d+)/sync'] ) ) {
	soocool_runtime_fail( 'SooCool REST order sync route was not registered.' );
}
if ( ! isset( $routes['/soocool/v1/webhook'] ) || ! isset( $routes['/soocool/v1/webhook/(?P<wc_order_id>\\d+)'] ) ) {
	soocool_runtime_fail( 'SooCool webhook routes were not registered.' );
}

$probe_request  = new WP_REST_Request( 'GET', '/soocool/v1/webhook/15061' );
$probe_response = $server->dispatch( $probe_request );
$probe_data     = $probe_response->get_data();
if ( 200 !== $probe_response->get_status() || ! is_array( $probe_data ) || true !== ( $probe_data['ready'] ?? false ) ) {
	soocool_runtime_fail( 'SooCool webhook readiness probe did not return HTTP 200 ready=true.' );
}
$probe_headers = $probe_response->get_headers();
if ( 'no-store' !== ( $probe_headers['Cache-Control'] ?? null ) ) {
	soocool_runtime_fail( 'SooCool webhook readiness probe must be non-cacheable.' );
}

$order = wc_create_order();
if ( ! $order instanceof WC_Order ) {
	soocool_runtime_fail( 'WooCommerce could not create a runtime probe order.' );
}

try {
	$order->set_billing_first_name( 'Runtime' );
	$order->set_billing_last_name( 'Probe' );
	$order->set_shipping_first_name( 'Runtime' );
	$order->set_shipping_last_name( 'Probe' );
	$order->set_shipping_address_1( 'Teststraat 1' );
	$order->set_shipping_city( 'Utrecht' );
	$order->set_shipping_postcode( '3511AA' );
	$order->set_shipping_country( 'NL' );
	$order->update_meta_data( '_soocool_runtime_probe', 'yes' );
	$order->save();

	$order_id = $order->get_id();
	if ( 0 >= $order_id ) {
		soocool_runtime_fail( 'Runtime probe order did not receive an ID.' );
	}

	$reloaded = wc_get_order( $order_id );
	if ( ! $reloaded instanceof WC_Order || 'yes' !== $reloaded->get_meta( '_soocool_runtime_probe', true ) ) {
		soocool_runtime_fail( 'WooCommerce HPOS order CRUD roundtrip failed.' );
	}

	$reloaded->delete( true );
} catch ( Throwable $throwable ) {
	if ( isset( $order ) && $order instanceof WC_Order && 0 < $order->get_id() ) {
		$order->delete( true );
	}
	soocool_runtime_fail( 'Runtime probe failed: ' . $throwable->getMessage() );
}

echo sprintf(
	"SooCool runtime smoke passed on WordPress %s, WooCommerce %s, PHP %s with HPOS enabled.\n",
	$wp_version,
	WC_VERSION,
	PHP_VERSION
);
