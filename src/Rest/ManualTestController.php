<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Admin\DebugRedactor;
use SooCool\WooCommerce\Admin\DummyOrderFactory;
use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\OrderSyncService;
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
		private readonly OptionRepository $options,
		private readonly OrderSyncService $sync
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
				unset( $payload['webhook'] );
				$mode    = 'dummy_woocommerce_order';
				$result  = $this->create_test_order( $payload, $mode );
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
				$result  = $this->find_or_create_real_order( $payload, $mode );
			}
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
		$settings     = $this->options->all();
		$environment  = (string) ( $settings['environment'] ?? 'test' );
		return in_array( $environment, array( 'test', 'production' ), true );
	}

	/** @param array<string, mixed> $payload @return array<string, mixed> */
	private function create_test_order( array $payload, string $mode ): array {
		$response = $this->client->create_order( $payload );
		$body     = $response->body();

		return array_merge(
			array( 'success' => false ),
			$this->request_context( $payload, $body ),
			array(
				'success' => true,
				'status'  => $response->status_code(),
				'message' => __( 'SooCool heeft de testorder aangemaakt in de actieve omgeving. Controleer het actieve SooCool-portaal op de getoonde orderreferentie.', 'soocool-for-woocommerce' ),
				'mode'    => $mode,
				'payload' => $this->redactor->redact( $payload ),
				'body'    => $this->redactor->redact( $body ),
			)
		);
	}

	/** @param array<string, mixed> $payload @return array<string, mixed> */
	private function find_or_create_real_order( array $payload, string $mode ): array {
		$order_reference = sanitize_text_field( (string) ( $payload['orderReference'] ?? '' ) );
		$existing_order  = '' !== $order_reference ? $this->sync->find_existing_order( $order_reference ) : array();

		if ( array() !== $existing_order ) {
			return $this->manual_test_result(
				$payload,
				$existing_order,
				$mode . '_existing',
				200,
				__( 'Bestaande SooCool-order gevonden in de actieve SooCool-omgeving. Er is geen dubbele order aangemaakt.', 'soocool-for-woocommerce' )
			);
		}

		$response = $this->client->create_order( $payload );
		$body     = $response->body();

		$created_order = '' !== $order_reference ? $this->sync->find_existing_order( $order_reference ) : array();
		if ( array() !== $created_order ) {
			$body = $created_order;
		}

		return $this->manual_test_result(
			$payload,
			$body,
			$mode,
			$response->status_code(),
			__( 'WooCommerce-order is aangemaakt in de actieve SooCool-omgeving en daarna opgehaald op orderreferentie.', 'soocool-for-woocommerce' )
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param mixed                $body
	 * @return array<string, mixed>
	 */
	private function manual_test_result( array $payload, mixed $body, string $mode, int $status, string $message ): array {
		return array_merge(
			array( 'success' => false ),
			$this->request_context( $payload, $body ),
			array(
				'success' => true,
				'status'  => $status,
				'message' => $message,
				'mode'    => $mode,
				'payload' => $this->redactor->redact( $payload ),
				'body'    => $this->redactor->redact( $body ),
			)
		);
	}

	/** @param array<string, mixed> $payload @return array<string, mixed> */
	private function with_unique_dummy_reference( array $payload ): array {
		$settings = $this->options->all();
		$prefix   = sanitize_key( (string) ( $settings['order_reference_prefix'] ?? '' ) );
		$prefix   = substr( $prefix, 0, 16 );
		$suffix   = gmdate( 'mdHi' ) . '-' . wp_rand( 100, 999 );

		$payload['orderReference'] = '' !== $prefix ? $prefix . '-test-' . $suffix : 'test-' . $suffix;

		return $payload;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param mixed                $body
	 * @return array<string, mixed>
	 */
	private function request_context( array $payload, mixed $body = null ): array {
		$settings = $this->options->all();
		$delivery_moments = $this->task_moments_from_payload( $payload, 'delivery' );
		$pickup_moments   = $this->task_moments_from_payload( $payload, 'pickup' );

		$context  = array(
			'environment'         => (string) ( $settings['environment'] ?? 'test' ),
			'api_base_url'        => $this->options->base_url(),
			'order_reference'     => sanitize_text_field( (string) ( $payload['orderReference'] ?? '' ) ),
			'portal_dates'        => $this->portal_dates_from_payload( $payload ),
			'portal_date_filters' => $this->portal_date_filters_from_payload( $payload ),
			'delivery_moments'    => $delivery_moments,
			'pickup_moments'      => $pickup_moments,
			'sender_summary'      => $this->sender_summary_from_payload( $payload ),
			'sender_included'     => array() !== $pickup_moments,
		);

		$soocool_order_id = $this->soocool_order_id_from_body( $body );
		if ( '' !== $soocool_order_id ) {
			$context['soocool_order_id'] = $soocool_order_id;
		}

		return $context;
	}

	/** @param array<string, mixed> $payload @return array<int, string> */
	private function portal_date_filters_from_payload( array $payload ): array {
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
			if ( 'delivery' !== $task_type ) {
				continue;
			}

			$window   = $task['timeWindow'] ?? array();
			$start    = is_array( $window ) ? (string) ( $window['startTime'] ?? '' ) : '';
			$start_ts = strtotime( $start );
			if ( false === $start_ts ) {
				continue;
			}

			$dates[] = wp_date( 'd-m-Y', $start_ts );
		}

		return array_values( array_unique( $dates ) );
	}

	/** @param array<string, mixed> $payload @return array<int, string> */
	private function task_moments_from_payload( array $payload, string $requested_type ): array {
		$moments = array();
		$tasks   = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) ) {
			return $moments;
		}

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) ) {
				continue;
			}

			$task_type = sanitize_key( (string) ( $task['taskType'] ?? '' ) );
			if ( $requested_type !== $task_type ) {
				continue;
			}

			$window   = $task['timeWindow'] ?? array();
			$start    = is_array( $window ) ? (string) ( $window['startTime'] ?? '' ) : '';
			$end      = is_array( $window ) ? (string) ( $window['endTime'] ?? '' ) : '';
			$start_ts = strtotime( $start );
			$end_ts   = strtotime( $end );
			if ( false === $start_ts ) {
				continue;
			}

			$value = wp_date( 'd-m-Y H:i', $start_ts );
			if ( false !== $end_ts ) {
				$value .= ' - ' . wp_date( wp_date( 'Y-m-d', $start_ts ) === wp_date( 'Y-m-d', $end_ts ) ? 'H:i' : 'd-m-Y H:i', $end_ts );
			}
			$moments[] = $value;
		}

		return array_values( array_unique( $moments ) );
	}

	/** @param array<string, mixed> $payload */
	private function sender_summary_from_payload( array $payload ): string {
		$tasks = $payload['tasks'] ?? array();
		if ( ! is_array( $tasks ) ) {
			return '';
		}

		foreach ( $tasks as $task ) {
			if ( ! is_array( $task ) || 'pickup' !== sanitize_key( (string) ( $task['taskType'] ?? '' ) ) ) {
				continue;
			}

			$address = $task['address'] ?? array();
			if ( ! is_array( $address ) ) {
				return __( 'Meegestuurd via ophaaltaak', 'soocool-for-woocommerce' );
			}

			$parts = array_filter(
				array(
					sanitize_text_field( (string) ( $address['person'] ?? '' ) ),
					trim( sanitize_text_field( (string) ( $address['street'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $address['houseNumber'] ?? '' ) ) ),
					trim( sanitize_text_field( (string) ( $address['postCode'] ?? '' ) ) . ' ' . sanitize_text_field( (string) ( $address['city'] ?? '' ) ) ),
				),
				static fn ( string $part ): bool => '' !== trim( $part )
			);

			return '' !== implode( ', ', $parts ) ? implode( ', ', $parts ) : __( 'Meegestuurd via ophaaltaak', 'soocool-for-woocommerce' );
		}

		return '';
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
			$end       = is_array( $window ) ? (string) ( $window['endTime'] ?? '' ) : '';
			$start_ts  = strtotime( $start );
			$end_ts    = strtotime( $end );
			if ( false === $start_ts ) {
				continue;
			}

			$label = 'pickup' === $task_type ? __( 'Ophalen', 'soocool-for-woocommerce' ) : __( 'Bezorgen', 'soocool-for-woocommerce' );
			$value = $label . ': ' . wp_date( 'd-m-Y H:i', $start_ts );
			if ( false !== $end_ts ) {
				$value .= ' - ' . wp_date( wp_date( 'Y-m-d', $start_ts ) === wp_date( 'Y-m-d', $end_ts ) ? 'H:i' : 'd-m-Y H:i', $end_ts );
			}
			$dates[] = $value;
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
