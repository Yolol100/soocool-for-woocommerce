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
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ManualTestController extends AbstractRestController {

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly DummyOrderFactory $dummy_orders,
		private readonly DebugRedactor $redactor,
		private readonly Logger $logger,
		private readonly OptionRepository $options
	) {}

	public function register_routes(): void {
		if ( ! $this->manual_tests_enabled() ) {
			return;
		}

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
		if ( ! $this->manual_tests_enabled() ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Handmatige SooCool API-tests zijn uitgeschakeld.', 'soocool-for-woocommerce' ),
				),
				404
			);
		}

		$result = array(
			'success' => false,
		);

		try {
			$mode = sanitize_key( (string) $request->get_param( 'test_mode' ) );
			if ( 'dummy' === $mode ) {
				$payload = $this->builder->build( $this->dummy_orders->create() );
				$payload = $this->with_unique_dummy_reference( $payload );
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
				$this->request_context( $payload, $response->body() ),
				array(
					'success' => true,
					'status'  => $response->status_code(),
					'message' => __( 'SooCool heeft de testaanvraag geaccepteerd. Controleer het juiste SooCool-portaal, de getoonde orderreferentie en de ophaal-/bezorgdatum uit de payload.', 'soocool-for-woocommerce' ),
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
				$result = array_merge( $result, $this->request_context( $payload ) );
				$result['payload'] = $this->redactor->redact( $payload );
			}
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Handmatige SooCool API-test onverwacht mislukt.',
				array(
					'error' => $exception->getMessage(),
				)
			);
			$result['message'] = __( 'De handmatige SooCool test is mislukt. Controleer de logs voor details.', 'soocool-for-woocommerce' );
		}

		return new WP_REST_Response( $result );
	}

	private function manual_tests_enabled(): bool {
		return defined( 'SOOCOOL_ENABLE_MANUAL_API_TESTS' ) && true === SOOCOOL_ENABLE_MANUAL_API_TESTS;
	}

	/** @param array<string, mixed> $payload @return array<string, mixed> */
	private function with_unique_dummy_reference( array $payload ): array {
		$reference = sanitize_text_field( (string) ( $payload['orderReference'] ?? '' ) );
		$suffix    = gmdate( 'YmdHis' ) . '-' . wp_rand( 1000, 9999 );
		$payload['orderReference'] = '' !== $reference ? $reference . '-test-' . $suffix : 'test-' . $suffix;

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param mixed                $body
	 * @return array<string, mixed>
	 */
	private function request_context( array $payload, mixed $body = null ): array {
		$settings = $this->options->all();
		$context  = array(
			'environment'     => (string) ( $settings['environment'] ?? 'test' ),
			'api_base_url'    => $this->options->base_url(),
			'order_reference' => sanitize_text_field( (string) ( $payload['orderReference'] ?? '' ) ),
			'portal_dates'    => $this->portal_dates_from_payload( $payload ),
		);

		$soocool_order_id = $this->soocool_order_id_from_body( $body );
		if ( '' !== $soocool_order_id ) {
			$context['soocool_order_id'] = $soocool_order_id;
		}

		return $context;
	}

	/** @param array<string, mixed> $payload @return array<int, string> */
	private function portal_dates_from_payload( array $payload ): array {
		$dates = array();
		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) ) {
			return $dates;
		}

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}
			$task_type = sanitize_key( (string) ( $task['taskType'] ?? '' ) );
			$window    = $task['timeWindow'] ?? array();
			$start     = is_array( $window ) ? (string) ( $window['startTime'] ?? '' ) : '';
			$timestamp = strtotime( $start );
			if ( false === $timestamp ) {
				continue;
			}
			$label = 'pickup' === $task_type ? __( 'Ophalen', 'soocool-for-woocommerce' ) : __( 'Bezorgen', 'soocool-for-woocommerce' );
			$dates[] = $label . ': ' . wp_date( 'd-m-Y', $timestamp );
		}

		return array_values( array_unique( $dates ) );
	}

	private function soocool_order_id_from_body( mixed $body ): string {
		if ( ! is_array( $body ) || ! isset( $body['orderId'] ) || is_array( $body['orderId'] ) || is_object( $body['orderId'] ) ) {
			return '';
		}

		$order_id = trim( sanitize_text_field( (string) $body['orderId'] ) );
		return ctype_digit( $order_id ) && 0 < (int) $order_id ? (string) (int) $order_id : '';
	}

}
