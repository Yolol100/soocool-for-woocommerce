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
		return $fields;
	}

	public function render_email_order_detail( WC_Order $order, bool $sent_to_admin, bool $plain_text ): void {
		$date_label = $this->order_delivery_label( $order );
		$time_label = $this->order_delivery_time_label( $order );
		if ( '' === $date_label ) {
			return;
		}

		$tracking_text = __( 'Je ontvangt Track & Trace zodra je bestelling onderweg is.', 'soocool-for-woocommerce' );

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Bezorging', 'soocool-for-woocommerce' ) . "\n";
			echo esc_html__( 'Bezorgdatum', 'soocool-for-woocommerce' ) . ': ' . esc_html( $date_label ) . "\n";
			if ( '' !== $time_label ) {
				echo esc_html__( 'Tijdvenster', 'soocool-for-woocommerce' ) . ': ' . esc_html( $time_label ) . "\n";
			}
			if ( ! $sent_to_admin ) {
				echo esc_html( $tracking_text ) . "\n";
			}
			return;
		}

		echo '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 24px 0;border-collapse:collapse;">';
		echo '<tr><td style="padding:0;">';
		echo '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse:collapse;background:#f7f7f7;border:1px solid #e5e5e5;border-left:4px solid #c5161d;border-radius:8px;">';
		echo '<tr><td style="padding:18px 20px;">';
		echo '<h2 style="margin:0 0 12px 0;color:#191919;font-size:20px;line-height:1.25;font-weight:700;text-align:center;">' . esc_html__( 'Bezorging', 'soocool-for-woocommerce' ) . '</h2>';
		echo '<p style="margin:0 0 6px 0;color:#191919;font-size:15px;line-height:1.5;"><strong>' . esc_html__( 'Bezorgdatum:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( $date_label ) . '</p>';
		if ( '' !== $time_label ) {
			echo '<p style="margin:0 0 6px 0;color:#191919;font-size:15px;line-height:1.5;"><strong>' . esc_html__( 'Tijdvenster:', 'soocool-for-woocommerce' ) . '</strong> ' . esc_html( $time_label ) . '</p>';
		}
		if ( ! $sent_to_admin ) {
			echo '<p style="margin:10px 0 0 0;color:#555555;font-size:14px;line-height:1.5;text-align:center;">' . esc_html( $tracking_text ) . '</p>';
		}
		echo '</td></tr></table>';
		echo '</td></tr></table>';
	}

	public function render_customer_order_detail( WC_Order $order ): void {
		$date_label = $this->order_delivery_label( $order );
		$time_label = $this->order_delivery_time_label( $order );
		if ( '' === $date_label ) {
			return;
		}

		echo '<section class="woocommerce-order-details soocool-order-delivery-detail">';
		echo '<h2 class="woocommerce-order-details__title soocool-order-delivery-detail__title" style="margin-top:1rem;">' . esc_html__( 'Bezorging', 'soocool-for-woocommerce' ) . '</h2>';
		echo '<div class="soocool-order-delivery-detail__card">';
		echo '<div class="soocool-order-delivery-detail__row"><span>' . esc_html__( 'Bezorgdatum', 'soocool-for-woocommerce' ) . '</span><strong>' . esc_html( $date_label ) . '</strong></div>';
		if ( '' !== $time_label ) {
			echo '<div class="soocool-order-delivery-detail__row"><span>' . esc_html__( 'Tijdvenster', 'soocool-for-woocommerce' ) . '</span><strong>' . esc_html( $time_label ) . '</strong></div>';
		}
		echo '<p class="soocool-order-delivery-detail__trace">' . esc_html__( 'Je ontvangt Track & Trace zodra je bestelling onderweg is.', 'soocool-for-woocommerce' ) . '</p>';
		echo '</div>';
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
}
