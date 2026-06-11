<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookController extends AbstractRestController {

	public function __construct(
		private readonly OptionRepository $options,
		private readonly OrderMeta $meta,
		private readonly Logger $logger
	) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => array( $this, 'can_receive' ),
			)
		);
	}

	public function can_receive( WP_REST_Request $request ): bool|WP_Error {
		$expected = $this->options->existing_webhook_secret();
		$provided = $this->provided_token( $request );

		if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'soocool_webhook_forbidden', __( 'Invalid SooCool webhook token.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'soocool_webhook_invalid_payload', __( 'Invalid SooCool webhook payload.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$soocool_order_id = $this->extract_numeric_string( $payload, array( 'orderId', 'soocoolOrderId', 'id' ) );
		$order_reference  = $this->extract_text( $payload, array( 'orderReference', 'ourReference', 'reference' ) );
		$order            = $this->find_order( $soocool_order_id, $order_reference );

		if ( ! $order instanceof WC_Order ) {
			$this->logger->error(
				'SooCool webhook order not found.',
				array(
					'status'  => 404,
					'path'    => '/webhook',
					'orderId' => '' !== $soocool_order_id ? $soocool_order_id : '[missing]',
				)
			);
			return new WP_Error( 'soocool_webhook_order_not_found', __( 'SooCool webhook order not found.', 'soocool-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$data = array(
			'status'        => $this->normalize_status( $this->extract_text( $payload, array( 'status', 'orderStatus', 'state', 'taskState' ) ) ),
			'tracking_code' => $this->extract_text( $payload, array( 'trackingCode', 'trackAndTrace', 'trackingNumber', 'tracking', 'code' ) ),
			'tracking_url'  => $this->extract_url( $payload, array( 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl', 'url', 'link' ) ),
		);

		$changed = $this->meta->save_webhook_update( $order, $data );
		if ( $changed ) {
			$order->add_order_note( $this->webhook_note( $data ) );
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'order_id' => $order->get_id(),
				'changed'  => $changed,
			),
			200
		);
	}

	private function provided_token( WP_REST_Request $request ): string {
		$token = $request->get_header( 'x-soocool-webhook-token' );
		if ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) {
			$token = $request->get_header( 'x_webhook_token' );
		}
		if ( ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) && $request->has_param( 'token' ) ) {
			$token = $request->get_param( 'token' );
		}

		return is_scalar( $token ) ? trim( sanitize_text_field( (string) $token ) ) : '';
	}

	private function find_order( string $soocool_order_id, string $order_reference ): ?WC_Order {
		if ( '' !== $soocool_order_id ) {
			$order = $this->find_order_by_meta( OrderMeta::ORDER_ID, $soocool_order_id );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		if ( '' !== $order_reference ) {
			foreach ( array( OrderMeta::ORDER_REFERENCE, OrderMeta::OUR_REFERENCE ) as $meta_key ) {
				$order = $this->find_order_by_meta( $meta_key, $order_reference );
				if ( $order instanceof WC_Order ) {
					return $order;
				}
			}
		}

		return null;
	}

	private function find_order_by_meta( string $meta_key, string $meta_value ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'objects',
				'meta_query' => array(
					array(
						'key'   => $meta_key,
						'value' => $meta_value,
					),
				),
			)
		);

		$order = is_array( $orders ) ? ( $orders[0] ?? null ) : null;
		return $order instanceof WC_Order ? $order : null;
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_numeric_string( array $payload, array $keys ): string {
		$value = $this->extract_text( $payload, $keys );
		return ctype_digit( $value ) && 0 < (int) $value ? (string) (int) $value : '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_text( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->deep_value( $payload, $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_url( array $payload, array $keys ): string {
		$value = $this->extract_text( $payload, $keys );
		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value );
		return false !== wp_http_validate_url( $url ) ? $url : '';
	}

	/** @param array<string, mixed> $payload */
	private function deep_value( array $payload, string $key ): mixed {
		if ( array_key_exists( $key, $payload ) ) {
			return $payload[ $key ];
		}

		foreach ( array( 'order', 'shipment', 'tracking', 'trackAndTrace', 'data' ) as $container ) {
			if ( isset( $payload[ $container ] ) && is_array( $payload[ $container ] ) && array_key_exists( $key, $payload[ $container ] ) ) {
				return $payload[ $container ][ $key ];
			}
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$nested = $this->deep_value( $value, $key );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return '';
		}

		return str_starts_with( $status, 'soocool_' ) ? $status : 'soocool_' . $status;
	}

	/** @param array<string, string> $data */
	private function webhook_note( array $data ): string {
		$parts = array();
		if ( '' !== ( $data['status'] ?? '' ) ) {
			$parts[] = sprintf(
				/* translators: %s: SooCool status. */
				__( 'status %s', 'soocool-for-woocommerce' ),
				sanitize_text_field( $data['status'] )
			);
		}
		if ( '' !== ( $data['tracking_code'] ?? '' ) ) {
			$parts[] = sprintf(
				/* translators: %s: tracking code. */
				__( 'tracking %s', 'soocool-for-woocommerce' ),
				sanitize_text_field( $data['tracking_code'] )
			);
		}

		return '' === implode( ', ', $parts )
			? __( 'SooCool webhook received.', 'soocool-for-woocommerce' )
			: sprintf(
				/* translators: %s: safe webhook summary. */
				__( 'SooCool webhook received: %s.', 'soocool-for-woocommerce' ),
				implode( ', ', $parts )
			);
	}
}
