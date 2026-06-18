<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\Logger;
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
		private readonly OrderMeta $meta,
		private readonly Logger $logger,
		private readonly WebhookAuthenticator $authenticator,
		private readonly WebhookPayloadExtractor $payloads
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
			return new WP_Error( 'soocool_webhook_order_not_found', __( 'SooCool webhook-order niet gevonden.', 'soocool-for-woocommerce' ), array( 'status' => 404 ) );
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
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Webhook must resolve a WooCommerce order by plugin-owned external references.
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Values are exact plugin-owned SooCool identifiers.
			)
		);

		$order = is_array( $orders ) ? ( $orders[0] ?? null ) : null;
		return $order instanceof WC_Order ? $order : null;
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
