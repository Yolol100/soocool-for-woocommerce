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

	private const SYNC_LOCK_TTL_SECONDS = 120;

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

		$order_id = (int) $order->get_id();
		if ( ! $this->acquire_sync_lock( $order_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'SooCool sync is already running for this order. Try again in a moment.', 'soocool-for-woocommerce' ),
				),
				409
			);
		}

		try {
			$this->meta->save_pending( $order );
			$payload        = $this->builder->build( $order );
			$existing_order = $this->find_existing_soocool_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$soocool_order_id = $this->meta->extract_order_id( $existing_order );
				$this->meta->save_success( $order, $existing_order );
				$order->add_order_note( __( 'Existing SooCool order found by order reference. WooCommerce order linked without creating a duplicate SooCool order.', 'soocool-for-woocommerce' ) );
				return new WP_REST_Response(
					array(
						'success'      => true,
						'orderId'      => $soocool_order_id,
						'ourReference' => isset( $existing_order['ourReference'] ) ? sanitize_text_field( (string) $existing_order['ourReference'] ) : '',
						'existing'     => true,
						'message'      => __( 'Existing SooCool order found by order reference. No duplicate SooCool order was created.', 'soocool-for-woocommerce' ),
					)
				);
			}

			$response         = $this->client->create_order( $payload );
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
					'existing'     => false,
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
				$this->safe_status_code( $exception->status_code() )
			);
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool sync failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
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
			$this->release_sync_lock( $order_id );
		}
	}
	private function acquire_sync_lock( int $order_id ): bool {
		$key     = $this->sync_lock_key( $order_id );
		$expires = (int) get_option( $key, 0 );
		$now     = time();

		if ( $expires > $now ) {
			return false;
		}

		if ( $expires > 0 ) {
			delete_option( $key );
		}

		return add_option( $key, (string) ( $now + self::SYNC_LOCK_TTL_SECONDS ), '', false );
	}

	private function release_sync_lock( int $order_id ): void {
		delete_option( $this->sync_lock_key( $order_id ) );
	}

	private function sync_lock_key( int $order_id ): string {
		return 'soocool_sync_lock_' . absint( $order_id );
	}

	/** @return array<string, mixed> */
	private function find_existing_soocool_order( string $order_reference ): array {
		$response = $this->client->search_order_by_reference( $order_reference );
		$body     = $response->body();

		if ( ! is_array( $body ) ) {
			return array();
		}

		$candidate = $body;
		if ( array_is_list( $body ) ) {
			$candidate = is_array( $body[0] ?? null ) ? $body[0] : array();
		}

		if ( ! is_array( $candidate ) || '' === $this->meta->extract_order_id( $candidate ) ) {
			return array();
		}

		return $candidate;
	}

	private function safe_status_code( int $status ): int {
		return $status >= 400 && $status <= 599 ? $status : 400;
	}

	private function public_api_error_message( ApiException $exception ): string {
		$message = sanitize_text_field( $exception->getMessage() );
		$errors  = array_map( 'sanitize_text_field', $exception->errors() );
		$errors  = array_values( array_filter( $errors, static fn ( string $error ): bool => '' !== $error ) );
		if ( $errors ) {
			$message .= ' (' . implode( '; ', $errors ) . ')';
		}

		return '' !== $message ? $message : __( 'SooCool sync failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
	}
}
