<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Checkout\DeliverySchedule;
use SooCool\WooCommerce\WooCommerce\OrderMeta;

defined( 'ABSPATH' ) || exit;

final class DummyOrderFactory {

	public function __construct( private readonly DeliverySchedule $schedule ) {}

	public function create(): \WC_Order {
		if ( ! class_exists( '\\WC_Order' ) || ! class_exists( '\\WC_Order_Item_Product' ) ) {
			throw new \InvalidArgumentException( esc_html__( 'WooCommerce orderklassen zijn niet beschikbaar voor de dummy testorder.', 'soocool-for-woocommerce' ) );
		}

		$order = new \WC_Order();
		if ( method_exists( $order, 'set_id' ) ) {
			$order->set_id( 999999 );
		}
		$order->set_currency( get_woocommerce_currency() ?: 'EUR' );
		$order->set_status( 'processing' );
		$order->set_billing_first_name( 'SooCool' );
		$order->set_billing_last_name( 'Testklant' );
		$order->set_billing_company( 'Testbedrijf B.V.' );
		$order->set_billing_address_1( 'Keizersgracht 123A' );
		$order->set_billing_postcode( '1015CJ' );
		$order->set_billing_city( 'Amsterdam' );
		$order->set_billing_country( 'NL' );
		$order->set_billing_email( 'testklant@example.com' );
		$order->set_billing_phone( '+31612345678' );
		$order->set_shipping_first_name( 'SooCool' );
		$order->set_shipping_last_name( 'Testklant' );
		$order->set_shipping_company( 'Testbedrijf B.V.' );
		$order->set_shipping_address_1( 'Keizersgracht 123A' );
		$order->set_shipping_postcode( '1015CJ' );
		$order->set_shipping_city( 'Amsterdam' );
		$order->set_shipping_country( 'NL' );
		$order->set_customer_note( 'Dummy testorder: gekoeld afleveren bij de hoofdingang.' );
		$this->apply_delivery_moment( $order );

		foreach ( $this->items() as $item_data ) {
			$item = new \WC_Order_Item_Product();
			$item->set_name( $item_data['name'] );
			$item->set_quantity( $item_data['quantity'] );
			$item->set_subtotal( $item_data['subtotal'] );
			$item->set_total( $item_data['total'] );
			$order->add_item( $item );
		}

		return $order;
	}


	private function apply_delivery_moment( \WC_Order $order ): void {
		foreach ( $this->schedule->available_options() as $option ) {
			$date = sanitize_text_field( (string) ( $option['date'] ?? '' ) );
			if ( '' === $date ) {
				continue;
			}

			$slots = $this->schedule->available_time_slots_for_date( $date );
			foreach ( $slots as $slot ) {
				$time_from = sanitize_text_field( (string) ( $slot['time_from'] ?? '' ) );
				$time_to   = sanitize_text_field( (string) ( $slot['time_to'] ?? '' ) );
				if ( '' === $time_from || '' === $time_to ) {
					continue;
				}

				$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_DATE, $date );
				$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_LABEL, $this->schedule->format_label( $date ) );
				$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, $time_from );
				$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_TO, $time_to );
				$order->update_meta_data( OrderMeta::REQUESTED_DELIVERY_TIME_LABEL, $this->schedule->format_time_slot_label( $time_from, $time_to ) );
				return;
			}
		}
	}

	/** @return array<int, array{name:string, quantity:int, subtotal:string, total:string}> */
	private function items(): array {
		return array(
			array(
				'name'     => 'SooCool dummy maaltijdbox gekoeld',
				'quantity' => 2,
				'subtotal' => '39.90',
				'total'    => '39.90',
			),
			array(
				'name'     => 'SooCool dummy dessertpakket',
				'quantity' => 1,
				'subtotal' => '12.50',
				'total'    => '12.50',
			),
		);
	}
}
