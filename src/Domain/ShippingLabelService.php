<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShippingLabelService {

	public function __construct( private readonly ApiClient $client, private readonly OrderMeta $meta ) {}

	public function get_label( WC_Order $order, string $output ): string {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( ! $soocool_order_id ) {
			throw new \RuntimeException( esc_html__( 'This order has no valid numeric SooCool order ID yet.', 'soocool-for-woocommerce' ) );
		}

		$response = $this->client->get_shipping_label( $soocool_order_id, $output );
		return (string) $response->body();
	}

	public function get_good_label( WC_Order $order, int|string $good_id, string $output ): string {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( ! $soocool_order_id ) {
			throw new \RuntimeException( esc_html__( 'This order has no valid numeric SooCool order ID yet.', 'soocool-for-woocommerce' ) );
		}

		$response = $this->client->get_good_shipping_label( $soocool_order_id, $good_id, $output );
		return (string) $response->body();
	}

	/** @param array<int, WC_Order> $orders */
	public function get_bulk_labels( array $orders, string $output ): string {
		$soocool_order_ids = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$soocool_order_id = $this->meta->get_soocool_order_id( $order );
				if ( '' !== $soocool_order_id ) {
					$soocool_order_ids[] = $soocool_order_id;
				}
			}
		}

		if ( array() === $soocool_order_ids ) {
			throw new \RuntimeException( esc_html__( 'No selected orders have a valid numeric SooCool order ID yet.', 'soocool-for-woocommerce' ) );
		}

		$response = $this->client->get_multiple_shipping_labels( $soocool_order_ids, $output );
		return (string) $response->body();
	}
}
