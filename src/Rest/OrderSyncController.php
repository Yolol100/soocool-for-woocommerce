<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\PayloadValidationException;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderSyncController extends AbstractRestController {

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderMeta $meta,
		private readonly OptionRepository $options
	) {}

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
					'message' => __( 'Order not found.', 'soocool-for-woocommerce' ),
				),
				404
			);
		}

		$settings = $this->options->all();
		$force    = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Order is already synced with SooCool.', 'soocool-for-woocommerce' ),
				),
				409
			);
		}

		try {
			$this->meta->save_pending( $order );
			$response         = $this->client->create_order( $this->builder->build( $order ) );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool did not return a valid order ID.', 'soocool-for-woocommerce' ) );
			}
			$this->meta->save_success( $order, $body );
			return new WP_REST_Response(
				array(
					'success'      => true,
					'orderId'      => $soocool_order_id,
					'ourReference' => isset( $body['ourReference'] ) ? sanitize_text_field( (string) $body['ourReference'] ) : '',
				)
			);
		} catch ( PayloadValidationException $exception ) {
			$this->meta->save_error( $order, $exception->getMessage() );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $exception->getMessage(),
				),
				422
			);
		} catch ( ApiException $exception ) {
			$message = $this->public_api_error_message( $exception );
			$this->meta->save_error( $order, $message );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $message,
				),
				400
			);
		}
	}
	private function public_api_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();

		return '' !== $message ? sanitize_text_field( $message ) : __( 'SooCool sync failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
	}
}
