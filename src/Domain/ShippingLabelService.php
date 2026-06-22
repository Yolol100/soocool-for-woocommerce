<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelService {

	public function __construct( private readonly ApiClient $client, private readonly OrderMeta $meta ) {}

	public function get_label( WC_Order $order, string $output ): string {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( ! $soocool_order_id ) {
			throw new \RuntimeException( esc_html__( 'Deze order heeft nog geen geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
		}

		$response = $this->client->get_shipping_label( $soocool_order_id, $output );
		return (string) $response->body();
	}

	public function get_good_label( WC_Order $order, int|string $good_id, string $output ): string {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( ! $soocool_order_id ) {
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
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				throw new \RuntimeException( esc_html__( 'Eén of meer geselecteerde orders zijn ongeldig voor SooCool labeldownload.', 'soocool-for-woocommerce' ) );
			}

			$soocool_order_id = $this->meta->get_soocool_order_id( $order );
			if ( '' === $soocool_order_id ) {
				throw new \RuntimeException( esc_html__( 'Eén of meer geselecteerde orders hebben nog geen geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
			}

			$soocool_order_ids[] = $soocool_order_id;
		}

		if ( array() === $soocool_order_ids ) {
			throw new \RuntimeException( esc_html__( 'Geen van de geselecteerde orders heeft al een geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
		}

		$available_order_ids = $this->available_soocool_order_ids( $soocool_order_ids );
		if ( array() === $available_order_ids ) {
			throw new \RuntimeException( esc_html__( 'Geen van de geselecteerde SooCool order-ID’s bestaat nog bij SooCool.', 'soocool-for-woocommerce' ) );
		}

		if ( 1 === count( $available_order_ids ) ) {
			$response = $this->client->get_shipping_label( $available_order_ids[0], $output );
			return (string) $response->body();
		}

		$response = $this->client->get_multiple_shipping_labels( $available_order_ids, $output );
		return (string) $response->body();
	}

	/** @param array<int, int|string> $soocool_order_ids @return array<int, string> */
	private function available_soocool_order_ids( array $soocool_order_ids ): array {
		$available = array();
		foreach ( array_values( array_unique( $soocool_order_ids ) ) as $soocool_order_id ) {
			try {
				$this->client->get_order( $soocool_order_id );
				$available[] = (string) $soocool_order_id;
			} catch ( ApiException $exception ) {
				if ( in_array( $exception->status_code(), array( 400, 404 ), true ) ) {
					continue;
				}
				throw $exception;
			}
		}

		return $available;
	}
}
