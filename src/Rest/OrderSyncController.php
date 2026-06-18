<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\OrderSyncService;
use SooCool\WooCommerce\Domain\PayloadValidationException;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\SecretSanitizer;
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
		private readonly OptionRepository $options,
		private readonly OrderSyncService $sync,
		private readonly SecretSanitizer $sanitizer
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
					'message' => __( 'Order niet gevonden.', 'soocool-for-woocommerce' ),
				),
				404
			);
		}

		$settings        = $this->options->all();
		$requested_force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );
		$force           = $requested_force && (bool) $settings['allow_resubmit'];
		if ( $requested_force && ! (bool) $settings['allow_resubmit'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Handmatig opnieuw versturen is uitgeschakeld in de SooCool-instellingen.', 'soocool-for-woocommerce' ),
				),
				403
			);
		}
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Order is al met SooCool gesynchroniseerd.', 'soocool-for-woocommerce' ),
				),
				409
			);
		}

		$order_id = (int) $order->get_id();
		if ( ! $this->sync->acquire_lock( $order_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'SooCool-synchronisatie draait al voor deze order. Probeer het zo opnieuw.', 'soocool-for-woocommerce' ),
				),
				409
			);
		}

		try {
			$this->meta->save_pending( $order );
			$payload        = $this->builder->build( $order );
			$existing_order = $this->sync->find_existing_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$soocool_order_id = $this->meta->extract_order_id( $existing_order );
				$this->meta->save_success( $order, $existing_order, (string) $payload['orderReference'] );
				$order->add_order_note( __( 'Bestaande SooCool-order gevonden op orderreferentie. WooCommerce-order gekoppeld zonder dubbele SooCool-order aan te maken.', 'soocool-for-woocommerce' ) );
				return new WP_REST_Response(
					array(
						'success'      => true,
						'orderId'      => $soocool_order_id,
						'ourReference' => isset( $existing_order['ourReference'] ) ? sanitize_text_field( (string) $existing_order['ourReference'] ) : sanitize_text_field( (string) $payload['orderReference'] ),
						'existing'     => true,
						'message'      => __( 'Bestaande SooCool-order gevonden op orderreferentie. Er is geen dubbele SooCool-order aangemaakt.', 'soocool-for-woocommerce' ),
					)
				);
			}

			$response         = $this->client->create_order( $payload );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool gaf geen geldig order-ID terug.', 'soocool-for-woocommerce' ) );
			}
			$this->meta->save_success( $order, $body, (string) $payload['orderReference'] );
			return new WP_REST_Response(
				array(
					'success'      => true,
					'orderId'      => $soocool_order_id,
					'ourReference' => isset( $body['ourReference'] ) ? sanitize_text_field( (string) $body['ourReference'] ) : sanitize_text_field( (string) $payload['orderReference'] ),
					'existing'     => false,
				)
			);
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $message,
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
				$this->safe_status_code( $exception->status_code() )
			);
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool-synchronisatie is onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );

			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $message,
				),
				500
			);
		} finally {
			$this->sync->release_lock( $order_id );
		}
	}


	private function safe_status_code( int $status ): int {
		return $status >= 400 && $status <= 599 ? $status : 400;
	}

	private function public_api_error_message( ApiException $exception ): string {
		$message = $this->redact_public_error_text( $exception->getMessage() );
		$errors  = array_map( array( $this, 'redact_public_error_text' ), $exception->errors() );
		$errors  = array_values( array_filter( $errors, static fn ( string $error ): bool => '' !== $error ) );
		if ( $errors ) {
			$message .= ' (' . implode( '; ', $errors ) . ')';
		}

		return '' !== $message ? $message : __( 'SooCool-synchronisatie mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' );
	}

	private function redact_public_error_text( string $value ): string {
		return $this->sanitizer->scrub_text( $value );
	}
}
