<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryEligibility {

	public function requires_delivery( WC_Order $order ): bool {
		$has_shippable_item = false;
		$items              = $order->get_items( 'line_item' );
		if ( is_iterable( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}

				$quantity = method_exists( $item, 'get_quantity' ) ? max( 0, (int) $item->get_quantity() ) : 1;
				if ( 0 < $quantity && method_exists( $order, 'get_qty_refunded_for_item' ) && method_exists( $item, 'get_id' ) ) {
					$item_id = (int) $item->get_id();
					if ( 0 < $item_id ) {
						$refunded_quantity = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );
						if ( is_finite( $refunded_quantity ) && 0 < $refunded_quantity ) {
							$quantity = max( 0, $quantity - min( $quantity, (int) round( $refunded_quantity ) ) );
						}
					}
				}

				if ( 0 === $quantity ) {
					continue;
				}

				$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
				if ( is_object( $product ) && method_exists( $product, 'is_virtual' ) && $product->is_virtual() ) {
					continue;
				}

				$has_shippable_item = true;
				break;
			}
		}

		if ( ! $has_shippable_item ) {
			return (bool) apply_filters( 'soocool_order_requires_delivery', false, $order );
		}

		$shipping_methods    = $order->get_shipping_methods();
		$has_shipping_method = false;
		$has_delivery_method = false;
		if ( is_iterable( $shipping_methods ) ) {
			foreach ( $shipping_methods as $shipping_method ) {
				if ( ! is_object( $shipping_method ) || ! method_exists( $shipping_method, 'get_method_id' ) ) {
					continue;
				}

				$method_id = strtolower( trim( sanitize_text_field( (string) $shipping_method->get_method_id() ) ) );
				if ( '' === $method_id ) {
					continue;
				}

				$has_shipping_method = true;
				if ( 'local_pickup' !== $method_id ) {
					$has_delivery_method = true;
				}
			}
		}
		if ( $has_shipping_method && ! $has_delivery_method ) {
			return (bool) apply_filters( 'soocool_order_requires_delivery', false, $order );
		}

		return (bool) apply_filters( 'soocool_order_requires_delivery', true, $order );
	}
}
