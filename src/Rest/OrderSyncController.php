<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Domain\OrderSyncCoordinator;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class OrderSyncController extends AbstractRestController {

	public function __construct( private readonly OrderSyncCoordinator $coordinator, private readonly OptionRepository $options ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/orders/(?P<id>\d+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'id'    => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => absint( $value ) > 0,
					),
					'force' => array(
						'type'              => 'boolean',
						'required'          => false,
						'sanitize_callback' => static fn ( $value ): bool => filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) $value,
					),
				),
			)
		);
	}

	public function sync( WP_REST_Request $request ): WP_REST_Response {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Order niet gevonden.', 'soocool-for-woocommerce' ),
				),
				404
			);
		}

		$settings        = $this->options->all();
		$requested_force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		if ( $requested_force && ! (bool) $settings['allow_resubmit'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Handmatig opnieuw versturen is uitgeschakeld in de SooCool-instellingen.', 'soocool-for-woocommerce' ),
				),
				403
			);
		}

		$result = $this->coordinator->sync_order( $order, $requested_force && (bool) $settings['allow_resubmit'] );
		$status = (int) ( $result['status'] ?? 200 );
		unset( $result['status'] );

		return new WP_REST_Response( $result, $status );
	}
}
