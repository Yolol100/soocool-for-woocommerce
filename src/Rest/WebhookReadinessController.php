<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class WebhookReadinessController extends AbstractRestController {

	public function register_routes(): void {
		foreach ( array( '/webhook', '/webhook/(?P<wc_order_id>\d+)' ) as $route ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'probe' ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}

	public function probe(): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'success' => true,
				'ready'   => true,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
