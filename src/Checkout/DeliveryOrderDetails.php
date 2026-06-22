<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Checkout;

use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class DeliveryOrderDetails {

	public function __construct( private readonly DeliverySchedule $schedule ) {}

	/** @param array<string, array<string, string>> $fields @return array<string, array<string, string>> */
	public function email_order_meta_fields( array $fields, bool $sent_to_admin, WC_Order $order ): array {
		$date_label = $this->order_delivery_label( $order );
		$time_label = $this->order_delivery_time_label( $order );

		if ( '' !== $date_label ) {
			$fields['soocool_requested_delivery_date'] = array(
				'label' => __( 'Bezorgdatum', 'soocool-for-woocommerce' ),
				'value' => $date_label,
			);
		}

		if ( '' !== $time_label ) {
			$fields['soocool_requested_delivery_daypart'] = array(
				'label' => __( 'Dagdeel', 'soocool-for-woocommerce' ),
				'value' => $time_label,
			);
		}

		if ( ! $sent_to_admin ) {
			$fields['soocool_tracking_notice'] = array(
				'label' => __( 'Track & Trace', 'soocool-for-woocommerce' ),
				'value' => __( 'Je ontvangt Track & Trace zodra je bestelling onderweg is.', 'soocool-for-woocommerce' ),
			);
		}

		return $fields;
	}

	public function render_customer_order_detail( WC_Order $order ): void {
		$label = $this->order_delivery_moment_label( $order );
		if ( '' === $label ) {
			return;
		}

		echo '<section class="woocommerce-order-details soocool-order-delivery-detail">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Bezorging', 'soocool-for-woocommerce' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Bezorgmoment:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( $label ) . '</p>';
		echo '</section>';
	}

	private function order_delivery_label( WC_Order $order ): string {
		$raw_label = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_LABEL, true );
		$label     = is_scalar( $raw_label ) ? sanitize_text_field( (string) $raw_label ) : '';
		if ( '' !== $label ) {
			return $label;
		}

		$raw_date = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_DATE, true );
		$date     = is_scalar( $raw_date ) ? sanitize_text_field( (string) $raw_date ) : '';

		return '' !== $date ? $this->schedule->format_label( $date ) : '';
	}

	private function order_delivery_time_label( WC_Order $order ): string {
		$raw_label = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_TIME_LABEL, true );
		$label     = is_scalar( $raw_label ) ? sanitize_text_field( (string) $raw_label ) : '';
		if ( '' !== $label ) {
			return $label;
		}

		$time_from = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_TIME_FROM, true );
		$time_to   = $order->get_meta( OrderMeta::REQUESTED_DELIVERY_TIME_TO, true );
		$time_from = is_scalar( $time_from ) ? sanitize_text_field( (string) $time_from ) : '';
		$time_to   = is_scalar( $time_to ) ? sanitize_text_field( (string) $time_to ) : '';

		return '' !== $time_from && '' !== $time_to ? $this->schedule->format_time_slot_label( $time_from, $time_to ) : '';
	}

	private function order_delivery_moment_label( WC_Order $order ): string {
		$date_label = $this->order_delivery_label( $order );
		$time_label = $this->order_delivery_time_label( $order );
		if ( '' === $date_label ) {
			return '';
		}

		return '' !== $time_label ? $date_label . ', ' . $time_label : $date_label;
	}
}
