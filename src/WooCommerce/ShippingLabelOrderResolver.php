<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelOrderResolver {

	public function __construct( private readonly OrderMeta $meta ) {}

	/** @param array<int, int> $order_ids @return array<int, WC_Order> */
	public function orders_from_ids( array $order_ids ): array {
		$orders = array();
		foreach ( $order_ids as $selected_order_id ) {
			$order = wc_get_order( $selected_order_id );
			if ( $order instanceof WC_Order ) {
				$orders[] = $order;
			}
		}

		return $orders;
	}

	/** @return array<int, int> */
	public function stored_good_ids( WC_Order $order ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $this->meta->get_good_ids( $order ) ) ) ) );
	}

	/** @param array<int, WC_Order> $orders @return array<int, int> */
	public function good_ids_from_orders( array $orders ): array {
		$good_ids = array();
		foreach ( $orders as $order ) {
			foreach ( $this->stored_good_ids( $order ) as $good_id ) {
				$good_ids[] = $good_id;
			}
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $good_ids ) ) ) );
	}
}
