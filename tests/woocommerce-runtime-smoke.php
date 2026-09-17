<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WP_REST_Request;

function soocool_runtime_fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

/** @param array<string, mixed> $endpoint @return array<int, string> */
function soocool_runtime_endpoint_methods( array $endpoint ): array {
	$methods = $endpoint['methods'] ?? array();
	if ( is_string( $methods ) ) {
		$methods = array_filter( array_map( 'trim', explode( ',', $methods ) ) );
	}
	if ( ! is_array( $methods ) ) {
		return array();
	}

	$normalized = array();
	foreach ( $methods as $method => $enabled ) {
		if ( is_string( $method ) && is_bool( $enabled ) ) {
			if ( $enabled ) {
				$normalized[] = strtoupper( $method );
			}
			continue;
		}
		if ( is_string( $enabled ) && '' !== trim( $enabled ) ) {
			$normalized[] = strtoupper( trim( $enabled ) );
		}
	}

	return array_values( array_unique( $normalized ) );
}

/** @param array<string, array<int, array<string, mixed>>> $routes */
function soocool_runtime_verify_rest_permissions( array $routes ): void {
	$public_readiness_routes = array(
		'/soocool/v1/webhook'                         => false,
		'/soocool/v1/webhook/(?P<wc_order_id>\\d+)' => false,
	);
	$protected_webhook_posts = array(
		'/soocool/v1/webhook'                         => false,
		'/soocool/v1/webhook/(?P<wc_order_id>\\d+)' => false,
	);

	foreach ( $routes as $route => $endpoints ) {
		if ( ! str_starts_with( (string) $route, '/soocool/v1/' ) || ! is_array( $endpoints ) ) {
			continue;
		}

		foreach ( $endpoints as $endpoint ) {
			if ( ! is_array( $endpoint ) || ! array_key_exists( 'permission_callback', $endpoint ) || ! is_callable( $endpoint['permission_callback'] ) ) {
				soocool_runtime_fail( 'Every SooCool REST endpoint must define a callable permission_callback: ' . $route );
			}

			$methods   = soocool_runtime_endpoint_methods( $endpoint );
			$permission = $endpoint['permission_callback'];
			$is_public  = '__return_true' === $permission;

			if ( $is_public ) {
				if ( ! array_key_exists( $route, $public_readiness_routes ) ) {
					soocool_runtime_fail( 'Unexpected public SooCool REST endpoint: ' . $route );
				}
				if ( ! in_array( 'GET', $methods, true ) || array_diff( $methods, array( 'GET', 'HEAD' ) ) ) {
					soocool_runtime_fail( 'Public SooCool webhook readiness endpoints must be read-only: ' . $route );
				}
				$public_readiness_routes[ $route ] = true;
			}

			if ( array_key_exists( $route, $protected_webhook_posts ) && in_array( 'POST', $methods, true ) ) {
				if ( $is_public ) {
					soocool_runtime_fail( 'SooCool POST webhook processing must never use a public permission callback.' );
				}
				$protected_webhook_posts[ $route ] = true;
			}
		}
	}

	foreach ( $public_readiness_routes as $route => $found ) {
		if ( ! $found ) {
			soocool_runtime_fail( 'Public read-only readiness endpoint missing: ' . $route );
		}
	}
	foreach ( $protected_webhook_posts as $route => $found ) {
		if ( ! $found ) {
			soocool_runtime_fail( 'Protected POST webhook endpoint missing: ' . $route );
		}
	}
}

$expected_wp   = trim( (string) getenv( 'SOOCOOL_EXPECT_WP' ) );
$expected_wc   = trim( (string) getenv( 'SOOCOOL_EXPECT_WC' ) );
$expected_hpos = strtolower( trim( (string) getenv( 'SOOCOOL_EXPECT_HPOS' ) ) );

if ( '' === $expected_wp || '' === $expected_wc || ! in_array( $expected_hpos, array( 'yes', 'no' ), true ) ) {
	soocool_runtime_fail( 'Expected WordPress, WooCommerce and HPOS mode must be provided.' );
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

if ( ! class_exists( OrderUtil::class ) ) {
	soocool_runtime_fail( 'WooCommerce OrderUtil is unavailable.' );
}
$hpos_enabled = OrderUtil::custom_orders_table_usage_is_enabled();
if ( ( 'yes' === $expected_hpos ) !== $hpos_enabled ) {
	soocool_runtime_fail( 'WooCommerce order storage mode mismatch.' );
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
soocool_runtime_verify_rest_permissions( $routes );

$base_probe_request  = new WP_REST_Request( 'GET', '/soocool/v1/webhook' );
$base_probe_response = $server->dispatch( $base_probe_request );
$base_probe_data     = $base_probe_response->get_data();
if ( 200 !== $base_probe_response->get_status() || ! is_array( $base_probe_data ) || true !== ( $base_probe_data['ready'] ?? false ) ) {
	soocool_runtime_fail( 'Base SooCool webhook readiness probe did not return HTTP 200 ready=true.' );
}

$probe_request = new WP_REST_Request( 'GET', '/soocool/v1/webhook/15061' );
$probe_request->set_query_params(
	array(
		'wc_order_id'     => '15061',
		'order_reference' => 'haknes-15061',
	)
);
$probe_response = $server->dispatch( $probe_request );
$probe_data     = $probe_response->get_data();
if ( 200 !== $probe_response->get_status() || ! is_array( $probe_data ) || true !== ( $probe_data['success'] ?? false ) || true !== ( $probe_data['ready'] ?? false ) ) {
	soocool_runtime_fail( 'Order-specific SooCool webhook readiness probe did not return HTTP 200 success=true ready=true.' );
}
if ( array_diff( array_keys( $probe_data ), array( 'success', 'ready' ) ) ) {
	soocool_runtime_fail( 'SooCool webhook readiness probe exposed unexpected request or order data.' );
}
$probe_headers = $probe_response->get_headers();
if ( 'no-store' !== ( $probe_headers['Cache-Control'] ?? null ) ) {
	soocool_runtime_fail( 'SooCool webhook readiness probe must be non-cacheable.' );
}

$head_request = new WP_REST_Request( 'HEAD', '/soocool/v1/webhook/15061' );
$head_request->set_query_params(
	array(
		'wc_order_id'     => '15061',
		'order_reference' => 'haknes-15061',
	)
);
$head_response = $server->dispatch( $head_request );
if ( 200 !== $head_response->get_status() ) {
	soocool_runtime_fail( 'SooCool webhook readiness route must accept HEAD probes.' );
}

$invalid_route_response = $server->dispatch( new WP_REST_Request( 'GET', '/soocool/v1/webhook/not-an-id' ) );
if ( 404 !== $invalid_route_response->get_status() ) {
	soocool_runtime_fail( 'Malformed SooCool order-specific webhook route must fail with HTTP 404.' );
}

$post_request = new WP_REST_Request( 'POST', '/soocool/v1/webhook/15061' );
$post_request->set_header( 'content-type', 'application/json' );
$post_request->set_body( '{}' );
$post_response = $server->dispatch( $post_request );
if ( 403 !== $post_response->get_status() ) {
	soocool_runtime_fail( 'Unauthenticated SooCool POST webhook must remain protected with HTTP 403.' );
}
$post_data = $post_response->get_data();
if ( ! is_array( $post_data ) || 'soocool_webhook_forbidden' !== ( $post_data['code'] ?? '' ) ) {
	soocool_runtime_fail( 'Unauthenticated SooCool POST webhook returned an unexpected error contract.' );
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
		soocool_runtime_fail( 'WooCommerce order CRUD roundtrip failed.' );
	}

	$reloaded->delete( true );
} catch ( Throwable $throwable ) {
	if ( isset( $order ) && $order instanceof WC_Order && 0 < $order->get_id() ) {
		$order->delete( true );
	}
	soocool_runtime_fail( 'Runtime probe failed: ' . $throwable->getMessage() );
}

$storage_label = $hpos_enabled ? 'HPOS' : 'legacy order storage';
echo sprintf(
	"SooCool runtime smoke passed on WordPress %s, WooCommerce %s, PHP %s with %s.\n",
	$wp_version,
	WC_VERSION,
	PHP_VERSION,
	$storage_label
);
