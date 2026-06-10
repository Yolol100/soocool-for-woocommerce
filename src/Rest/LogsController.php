<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\Logger;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogsController extends AbstractRestController {

	public function __construct( private readonly Logger $logger ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function get(): WP_REST_Response {
		return new WP_REST_Response( $this->logger->recent() );
	}

	public function clear(): WP_REST_Response {
		$this->logger->clear();
		return new WP_REST_Response( array( 'success' => true ) );
	}
}
