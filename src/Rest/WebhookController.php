<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class WebhookController extends AbstractRestController {

	public function __construct(
		private readonly OrderMeta $meta,
		private readonly Logger $logger,
		private readonly WebhookAuthenticator $authenticator,
		private readonly WebhookPayloadExtractor $payloads,
		private readonly ApiClient $client
	) {}

	public function register_routes(): void {
		foreach ( array( '/webhook', '/webhook/(?P<wc_order_id>\d+)' ) as $route ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => array( $this, 'can_receive' ),
				)
			);
		}
	}

	public function can_receive( WP_REST_Request $request ): bool|WP_Error {
		return $this->authenticator->can_receive( $request );
	}

	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw_body = $request->get_body();
		if ( is_string( $raw_body ) && strlen( $raw_body ) > WebhookPayloadExtractor::MAX_PAYLOAD_BYTES ) {
			return new WP_Error( 'soocool_webhook_payload_too_large', __( 'SooCool webhook-payload is te groot.', 'soocool-for-woocommerce' ), array( 'status' => 413 ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! $this->payloads->shape_is_safe( $payload ) ) {
			return new WP_Error( 'soocool_webhook_invalid_payload', __( 'Ongeldige SooCool webhook-payload.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}

		$soocool_order_id = $this->payloads->soocool_order_id( $payload );
		$order_reference  = $this->payloads->order_reference( $payload );
		if ( '' === $order_reference ) {
			$order_reference = $this->webhook_order_reference( $request );
		}
		$wc_order_id = $this->webhook_wc_order_id( $request );
		if ( 0 >= $wc_order_id ) {
			$wc_order_id = $this->payloads->wc_order_id( $payload );
		}
		$order       = $this->find_order( $soocool_order_id, $order_reference, $wc_order_id );

		if ( ! $order instanceof WC_Order ) {
			$this->logger->info(
				'SooCool webhook genegeerd: WooCommerce-order niet gevonden.',
				array(
					'status'         => 202,
					'path'           => sanitize_text_field( (string) $request->get_route() ),
					'orderId'        => '' !== $soocool_order_id ? $soocool_order_id : '[missing]',
					'orderReference' => '' !== $order_reference ? $order_reference : '[missing]',
					'wcOrderId'      => 0 < $wc_order_id ? (string) $wc_order_id : '[missing]',
				)
			);
			return new WP_REST_Response(
				array(
					'success' => true,
					'ignored' => true,
					'reason'  => 'order_not_found',
				),
				202
			);
		}

		$data = $this->payloads->update_data( $payload );

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

	private function find_order( string $soocool_order_id, string $order_reference, int $wc_order_id ): ?WC_Order {
		if ( '' !== $soocool_order_id ) {
			$order = $this->find_order_by_meta( OrderMeta::ORDER_ID, $soocool_order_id );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		if ( '' !== $order_reference ) {
			$order = $this->find_order_by_reference( $order_reference );
			if ( $order instanceof WC_Order ) {
				$this->link_known_webhook_order( $order, $soocool_order_id, $order_reference );
				return $order;
			}
		}

		if ( 0 < $wc_order_id ) {
			$order = $this->find_order_by_wc_order_id( $wc_order_id );
			if ( $order instanceof WC_Order ) {
				$this->link_known_webhook_order( $order, $soocool_order_id, $order_reference );
				return $order;
			}
		}

		$remote_order = '' !== $soocool_order_id ? $this->remote_order( $soocool_order_id ) : array();
		if ( array() !== $remote_order ) {
			$remote_reference = $this->payloads->order_reference( $remote_order );
			if ( '' !== $remote_reference ) {
				$order = $this->find_order_by_reference( $remote_reference );
				if ( $order instanceof WC_Order ) {
					$this->link_remote_order( $order, $remote_order, $remote_reference );
					return $order;
				}
			}
		}

		return null;
	}

	private function find_order_by_reference( string $order_reference ): ?WC_Order {
		foreach ( array( OrderMeta::ORDER_REFERENCE, OrderMeta::OUR_REFERENCE ) as $meta_key ) {
			$order = $this->find_order_by_meta( $meta_key, $order_reference );
			if ( $order instanceof WC_Order ) {
				return $order;
			}
		}

		return $this->find_order_by_order_number( $order_reference );
	}

	private function find_order_by_meta( string $meta_key, string $meta_value ): ?WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'objects',
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Webhook must resolve a WooCommerce order by plugin-owned external references.
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Values are exact plugin-owned SooCool identifiers.
			)
		);

		$order = is_array( $orders ) ? ( $orders[0] ?? null ) : null;
		return $order instanceof WC_Order ? $order : null;
	}

	private function find_order_by_order_number( string $order_reference ): ?WC_Order {
		$candidates = array( trim( sanitize_text_field( $order_reference ) ) );
		if ( 1 === preg_match( '/(\d+)$/', $order_reference, $matches ) ) {
			$candidates[] = $matches[1];
		}

		foreach ( array_values( array_unique( $candidates ) ) as $candidate ) {
			if ( ! ctype_digit( $candidate ) || 0 >= (int) $candidate ) {
				continue;
			}

			$order = wc_get_order( (int) $candidate );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_number = sanitize_text_field( (string) $order->get_order_number() );
			if ( $order_number === $order_reference || $order_number === $candidate || (string) $order->get_id() === $candidate ) {
				return $order;
			}
		}

		return null;
	}

	private function find_order_by_wc_order_id( int $order_id ): ?WC_Order {
		if ( 0 >= $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/** @return array<string, mixed> */
	private function remote_order( string $soocool_order_id ): array {
		try {
			$response = $this->client->get_order( $soocool_order_id );
		} catch ( ApiException ) {
			return array();
		}

		$body = $response->body();
		if ( ! is_array( $body ) ) {
			return array();
		}

		if ( array_is_list( $body ) ) {
			$body = is_array( $body[0] ?? null ) ? $body[0] : array();
		}

		foreach ( array( 'order', 'data' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				$candidate = $body[ $key ];
				if ( isset( $candidate['order'] ) && is_array( $candidate['order'] ) ) {
					$candidate = $candidate['order'];
				}
				return $candidate;
			}
		}

		return $body;
	}

	private function link_known_webhook_order( WC_Order $order, string $soocool_order_id, string $order_reference ): void {
		if ( '' === $soocool_order_id ) {
			return;
		}

		$current_soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' !== $current_soocool_order_id && $current_soocool_order_id !== $soocool_order_id ) {
			return;
		}

		$body = array( 'orderId' => $soocool_order_id );
		if ( '' !== $order_reference ) {
			$body['orderReference'] = $order_reference;
		}

		$this->link_remote_order( $order, $body, $order_reference );
	}

	/** @param array<string, mixed> $remote_order */
	private function link_remote_order( WC_Order $order, array $remote_order, string $order_reference ): void {
		try {
			$this->meta->save_success( $order, $remote_order, $order_reference );
		} catch ( \InvalidArgumentException ) {
			// Keep the webhook update working even when the remote lookup response omits a stable orderId.
		}
	}

	private function webhook_wc_order_id( WP_REST_Request $request ): int {
		$route_value = $request->get_param( 'wc_order_id' );
		if ( is_scalar( $route_value ) && ctype_digit( trim( (string) $route_value ) ) && 0 < (int) $route_value ) {
			return (int) $route_value;
		}

		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			return 0;
		}

		foreach ( array( 'wc_order_id', 'woo_order_id', 'woocommerce_order_id' ) as $key ) {
			$value = $params[ $key ] ?? null;
			if ( is_scalar( $value ) && ctype_digit( trim( (string) $value ) ) && 0 < (int) $value ) {
				return (int) $value;
			}
		}

		return 0;
	}

	private function webhook_order_reference( WP_REST_Request $request ): string {
		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			return '';
		}

		foreach ( array( 'order_reference', 'orderReference', 'soocool_order_reference', 'reference' ) as $key ) {
			$value = $params[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
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
			? __( 'SooCool webhook ontvangen.', 'soocool-for-woocommerce' )
			: sprintf(
				/* translators: %s: safe webhook summary. */
				__( 'SooCool webhook ontvangen: %s.', 'soocool-for-woocommerce' ),
				implode( ', ', $parts )
			);
	}
}
