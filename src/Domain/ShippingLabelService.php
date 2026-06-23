<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelService {

	public function __construct( private readonly ApiClient $client, private readonly OrderMeta $meta ) {}

	public function get_label( WC_Order $order, string $output ): string {
		$soocool_order_id = $this->resolved_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			throw new \RuntimeException( esc_html__( 'Deze order heeft nog geen geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
		}

		$response = $this->client->get_shipping_label( $soocool_order_id, $output );
		return (string) $response->body();
	}

	public function get_good_label( WC_Order $order, int|string $good_id, string $output ): string {
		$soocool_order_id = $this->resolved_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			throw new \RuntimeException( esc_html__( 'Deze order heeft nog geen geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
		}

		$response = $this->client->get_good_shipping_label( $soocool_order_id, $good_id, $output );
		return (string) $response->body();
	}

	/** @param array<int, int|string> $good_ids */
	public function get_bulk_good_labels( array $good_ids, string $output ): string {
		$response = $this->client->get_multiple_good_shipping_labels( $good_ids, $output );
		return (string) $response->body();
	}

	/** @param array<int, WC_Order> $orders */
	public function get_bulk_labels( array $orders, string $output ): string {
		$soocool_order_ids = array();
		$unresolved       = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				++$unresolved;
				continue;
			}

			$soocool_order_id = $this->resolved_soocool_order_id( $order );
			if ( '' !== $soocool_order_id ) {
				$soocool_order_ids[] = $soocool_order_id;
				continue;
			}

			++$unresolved;
		}

		$soocool_order_ids = array_values( array_unique( $soocool_order_ids ) );
		if ( array() === $soocool_order_ids ) {
			throw new \RuntimeException( esc_html__( 'Geen van de geselecteerde orders is teruggevonden bij SooCool. Synchroniseer de orders opnieuw en probeer daarna opnieuw te downloaden.', 'soocool-for-woocommerce' ) );
		}

		if ( $unresolved > 0 ) {
			throw new \RuntimeException( esc_html__( 'Eén of meer geselecteerde orders zijn niet teruggevonden bij SooCool. Synchroniseer deze orders opnieuw en probeer daarna opnieuw te downloaden.', 'soocool-for-woocommerce' ) );
		}

		$response = 1 === count( $soocool_order_ids )
			? $this->client->get_shipping_label( $soocool_order_ids[0], $output )
			: $this->client->get_multiple_shipping_labels( $soocool_order_ids, $output );

		return (string) $response->body();
	}

	private function resolved_soocool_order_id( WC_Order $order ): string {
		$remote_order = $this->remote_order_by_reference( $order );
		if ( array() !== $remote_order ) {
			return $this->remember_remote_order( $order, $remote_order );
		}

		$stored_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $stored_order_id ) {
			return '';
		}

		$remote_order = $this->remote_order_by_id( $stored_order_id );
		if ( array() !== $remote_order ) {
			return $this->remember_remote_order( $order, $remote_order );
		}

		return '';
	}

	/** @return array<string, mixed> */
	private function remote_order_by_reference( WC_Order $order ): array {
		foreach ( $this->order_reference_candidates( $order ) as $reference ) {
			try {
				$response = $this->client->search_order_by_reference( $reference );
			} catch ( \Throwable ) {
				continue;
			}

			$remote_order = $this->first_order_from_search_response( $response->body(), $reference );
			if ( array() !== $remote_order ) {
				return $remote_order;
			}
		}

		return array();
	}

	/** @return array<string, mixed> */
	private function remote_order_by_id( string $soocool_order_id ): array {
		try {
			$response = $this->client->get_order( $soocool_order_id );
		} catch ( \Throwable ) {
			return array();
		}

		$body = $response->body();
		return is_array( $body ) && '' !== $this->meta->extract_order_id( $body ) ? $body : array();
	}

	/** @return array<int, string> */
	private function order_reference_candidates( WC_Order $order ): array {
		$candidates = array(
			$this->meta->get_order_reference( $order ),
			(string) $order->get_order_number(),
		);

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( string $candidate ): string => trim( sanitize_text_field( $candidate ) ),
						$candidates
					)
				)
			)
		);
	}

	/**
	 * @param mixed $body
	 * @return array<string, mixed>
	 */
	private function first_order_from_search_response( mixed $body, string $reference ): array {
		if ( ! is_array( $body ) ) {
			return array();
		}

		$first_valid = array();
		foreach ( $body as $remote_order ) {
			if ( ! is_array( $remote_order ) || '' === $this->meta->extract_order_id( $remote_order ) ) {
				continue;
			}

			if ( array() === $first_valid ) {
				$first_valid = $remote_order;
			}

			$remote_reference = isset( $remote_order['orderReference'] ) && ! is_array( $remote_order['orderReference'] ) && ! is_object( $remote_order['orderReference'] )
				? trim( sanitize_text_field( (string) $remote_order['orderReference'] ) )
				: '';

			if ( $remote_reference === $reference ) {
				return $remote_order;
			}
		}

		return $first_valid;
	}

	/** @param array<string, mixed> $remote_order */
	private function remember_remote_order( WC_Order $order, array $remote_order ): string {
		$soocool_order_id = $this->meta->extract_order_id( $remote_order );
		if ( '' === $soocool_order_id ) {
			return '';
		}

		try {
			$this->meta->save_success( $order, $remote_order, $this->meta->get_order_reference( $order ) );
		} catch ( \Throwable ) {
			return $soocool_order_id;
		}

		return $soocool_order_id;
	}
}
