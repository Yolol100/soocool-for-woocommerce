<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Admin\DebugRedactor;
use SooCool\WooCommerce\Admin\DummyOrderFactory;
use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\PayloadValidationException;
use SooCool\WooCommerce\Infrastructure\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ManualTestController extends AbstractRestController {

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly DummyOrderFactory $dummy_orders,
		private readonly DebugRedactor $redactor,
		private readonly Logger $logger
	) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/manual-test/order',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'test_mode'            => array(
						'type'              => 'string',
						'default'           => 'real',
						'enum'              => array( 'real', 'dummy' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'woocommerce_order_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function run( WP_REST_Request $request ): WP_REST_Response {
		$result = array(
			'success' => false,
		);

		try {
			$mode = sanitize_key( (string) $request->get_param( 'test_mode' ) );
			if ( 'dummy' === $mode ) {
				$payload = $this->builder->build( $this->dummy_orders->create() );
				$mode    = 'dummy_woocommerce_order';
			} else {
				$order_id = absint( $request->get_param( 'woocommerce_order_id' ) );
				if ( 0 >= $order_id ) {
					throw new \InvalidArgumentException( esc_html__( 'Vul een WooCommerce order-ID in of kies Testorder.', 'soocool-for-woocommerce' ) );
				}

				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					throw new \InvalidArgumentException( esc_html__( 'WooCommerce order niet gevonden.', 'soocool-for-woocommerce' ) );
				}

				$payload = $this->builder->build( $order );
				$mode    = 'woocommerce_order';
			}

			$response = $this->client->create_order( $payload );
			$result   = array_merge(
				$result,
				array(
					'success' => true,
					'status'  => $response->status_code(),
					'message' => __( 'SooCool heeft de testaanvraag geaccepteerd.', 'soocool-for-woocommerce' ),
					'mode'    => $mode,
					'payload' => $this->redactor->redact( $payload ),
					'body'    => $this->redactor->redact( $response->body() ),
				)
			);
		} catch ( PayloadValidationException|\InvalidArgumentException $exception ) {
			$result['message'] = sanitize_text_field( $exception->getMessage() );
		} catch ( ApiException $exception ) {
			$result['status']  = $exception->status_code();
			$result['message'] = sanitize_text_field( $exception->getMessage() );
			$result['errors']  = $this->redactor->redact_error_list( $exception->errors() );
			if ( isset( $payload ) && is_array( $payload ) ) {
				$result['payload'] = $this->redactor->redact( $payload );
			}
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Manual SooCool API test failed unexpectedly.',
				array(
					'error' => $exception->getMessage(),
				)
			);
			$result['message'] = __( 'De handmatige SooCool test is mislukt. Controleer de logs voor details.', 'soocool-for-woocommerce' );
		}

		return new WP_REST_Response( $result );
	}
}
