<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\Logger;
use WP_Error;
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
						'limit'    => array(
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'offset'   => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'level'    => array(
							'type'              => 'string',
							'default'           => '',
							'enum'              => array( '', 'info', 'error' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'order_id' => array(
							'type'              => 'integer',
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
						'date_from' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_date' ),
						),
						'date_to'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => array( $this, 'validate_date' ),
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

	public function get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit     = max( 1, min( 100, absint( $request->get_param( 'limit' ) ) ) );
		$offset    = max( 0, absint( $request->get_param( 'offset' ) ) );
		$level     = sanitize_key( (string) $request->get_param( 'level' ) );
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$order_id  = absint( $request->get_param( 'order_id' ) );
		$date_from = sanitize_text_field( (string) $request->get_param( 'date_from' ) );
		$date_to   = sanitize_text_field( (string) $request->get_param( 'date_to' ) );
		if ( '' !== $date_from && '' !== $date_to && $date_from > $date_to ) {
			return new WP_Error(
				'soocool_invalid_log_date_range',
				__( 'De begindatum van het logfilter mag niet na de einddatum liggen.', 'soocool-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$result    = $this->logger->query( $limit, $offset, $level, $search, $order_id, $date_from, $date_to );
		$total     = (int) $result['total'];

		return new WP_REST_Response(
			array(
				'items'    => $result['items'],
				'total'    => $total,
				'limit'    => $limit,
				'offset'   => $offset,
				'has_more' => $offset + $limit < $total,
			)
		);
	}

	public function validate_date( mixed $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = (string) $value;
		if ( '' === $value ) {
			return true;
		}

		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return false;
		}

		return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
	}

	public function clear(): WP_REST_Response {
		$this->logger->clear();
		return new WP_REST_Response( array( 'success' => true ) );
	}
}
