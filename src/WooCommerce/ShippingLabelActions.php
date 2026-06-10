<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Infrastructure\OptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShippingLabelActions {

	public function __construct( private readonly ShippingLabelService $labels, private readonly OptionRepository $options ) {}

	public function register(): void {
		add_action( 'admin_post_soocool_download_label', array( $this, 'download' ) );
	}

	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this label.', 'soocool-for-woocommerce' ) );
		}

		$requested_output = sanitize_key( (string) filter_input( INPUT_GET, 'output', FILTER_UNSAFE_RAW ) );
		if ( '' === $requested_output ) {
			$settings         = $this->options->all();
			$requested_output = (string) $settings['label_output'];
		}
		$output = 'collated_a4' === $requested_output ? 'collated_a4' : 'a6';

		$has_bulk_request = $this->has_bulk_order_ids_request();
		$order_ids        = $this->request_order_ids();
		if ( $has_bulk_request ) {
			check_admin_referer( 'soocool_download_labels_bulk' );
			if ( array() === $order_ids ) {
				wp_die( esc_html__( 'No valid orders selected for SooCool label download.', 'soocool-for-woocommerce' ) );
			}

			$orders = array();
			foreach ( $order_ids as $selected_order_id ) {
				$order = wc_get_order( $selected_order_id );
				if ( ! $order ) {
					wp_die( esc_html__( 'One or more selected orders could not be found for SooCool label download.', 'soocool-for-woocommerce' ) );
				}
				$orders[] = $order;
			}

			try {
				$pdf = $this->labels->get_bulk_labels( $orders, $output );
			} catch ( \Throwable $exception ) {
				wp_die( esc_html__( 'SooCool bulk label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
			}

			$filename = count( $order_ids ) > 1 ? 'soocool-labels.pdf' : 'soocool-label-' . absint( $order_ids[0] ) . '.pdf';
			$this->send_pdf( $pdf, $filename );
		}

		$order_id = absint( filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT ) ?: 0 );
		check_admin_referer( 'soocool_download_label_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'soocool-for-woocommerce' ) );
		}

		$good_id = absint( filter_input( INPUT_GET, 'good_id', FILTER_VALIDATE_INT ) ?: 0 );
		try {
			$pdf = $good_id > 0 ? $this->labels->get_good_label( $order, $good_id, $output ) : $this->labels->get_label( $order, $output );
		} catch ( \Throwable $exception ) {
			wp_die( esc_html__( 'SooCool label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
		}

		$filename = $good_id > 0 ? 'soocool-label-' . absint( $order_id ) . '-good-' . absint( $good_id ) . '.pdf' : 'soocool-label-' . absint( $order_id ) . '.pdf';
		$this->send_pdf( $pdf, $filename );
	}

	private function has_bulk_order_ids_request(): bool {
		return null !== filter_input( INPUT_GET, 'order_ids', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE );
	}

	/** @return array<int, int> */
	private function request_order_ids(): array {
		$order_ids = sanitize_text_field( (string) filter_input( INPUT_GET, 'order_ids', FILTER_UNSAFE_RAW ) );
		if ( '' === $order_ids ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', $order_ids ) as $order_id ) {
			$id = absint( $order_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function send_pdf( string $pdf, string $filename ): void {
		if ( '' === $pdf || ! str_starts_with( ltrim( $pdf ), '%PDF' ) ) {
			wp_die( esc_html__( 'SooCool did not return a valid PDF label.', 'soocool-for-woocommerce' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
		exit;
	}
}
