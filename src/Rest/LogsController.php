<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

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
					'args'                => array(
						'limit'  => array(
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'offset' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		$limit  = max( 1, min( 100, absint( $request->get_param( 'limit' ) ) ) );
		$offset = max( 0, absint( $request->get_param( 'offset' ) ) );
		$total  = $this->logger->count();

		return new WP_REST_Response(
			array(
				'items'    => $this->logger->recent( $limit, $offset ),
				'total'    => $total,
				'limit'    => $limit,
				'offset'   => $offset,
				'has_more' => $offset + $limit < $total,
			)
		);
	}

	public function clear(): WP_REST_Response {
		$this->logger->clear();
		return new WP_REST_Response( array( 'success' => true ) );
	}
}
