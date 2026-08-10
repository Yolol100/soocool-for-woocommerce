<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderStatusHooks {

	public function __construct( private readonly OptionRepository $options, private readonly OrderActions $actions, private readonly OrderMeta $meta ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_submit' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'maybe_auto_submit_created_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'maybe_auto_submit_created_order' ), 20 );
	}

	public function maybe_auto_submit( int $order_id, string $old_status, string $new_status, mixed $order = null ): void {
		unset( $old_status );
		$settings = $this->options->all();
		if ( ! (bool) $settings['auto_submit_enabled'] || $new_status !== (string) $settings['auto_submit_status'] ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $this->order_requires_delivery( $order ) ) {
			return;
		}

		$this->schedule_order(
			$order,
			__( 'SooCool-synchronisatie is ingepland op de achtergrond na de orderstatuswijziging.', 'soocool-for-woocommerce' ),
			true
		);
	}

	public function maybe_auto_submit_created_order( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! (bool) $settings['auto_submit_enabled'] || ! $this->created_order_matches_auto_submit_status( $order->get_status(), (string) $settings['auto_submit_status'] ) ) {
			return;
		}
		if ( ! $this->order_requires_delivery( $order ) ) {
			return;
		}

		$this->schedule_order(
			$order,
			__( 'SooCool-synchronisatie is direct na het aanmaken van de order op de achtergrond ingepland.', 'soocool-for-woocommerce' ),
			false
		);
	}

	private function schedule_order( WC_Order $order, string $scheduled_note, bool $note_duplicate ): void {
		if ( $this->meta->is_synced( $order ) ) {
			return;
		}

		$result = $this->actions->schedule_send_to_soocool( (int) $order->get_id() );
		if ( OrderActions::QUEUE_SCHEDULED === $result ) {
			$order->add_order_note( $scheduled_note );
			return;
		}

		if ( OrderActions::QUEUE_DUPLICATE === $result ) {
			if ( $note_duplicate ) {
				$order->add_order_note( __( 'SooCool-synchronisatie is overgeslagen omdat deze order al op de achtergrond ingepland staat.', 'soocool-for-woocommerce' ) );
			}
			return;
		}

		$order->add_order_note( __( 'SooCool-synchronisatie kon niet op de achtergrond worden ingepland. Gebruik de knop “Synchroniseer nu met SooCool” of controleer WooCommerce Action Scheduler en WP-Cron.', 'soocool-for-woocommerce' ) );
	}

	private function created_order_matches_auto_submit_status( string $order_status, string $configured_status ): bool {
		$order_status      = sanitize_key( $order_status );
		$configured_status = sanitize_key( $configured_status );

		if ( $order_status === $configured_status ) {
			return true;
		}

		if ( 'pending' === $configured_status ) {
			return in_array( $order_status, array( 'on-hold', 'processing', 'completed' ), true );
		}

		return 'processing' === $configured_status && 'completed' === $order_status;
	}

	private function order_requires_delivery( WC_Order $order ): bool {
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
