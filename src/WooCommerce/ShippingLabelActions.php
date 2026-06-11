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

		// Bulk "Download SooCool labels" in both the HPOS and legacy order list tables.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_bulk_action( array $actions ): array {
		$actions['soocool_download_labels'] = __( 'Download SooCool labels', 'soocool-for-woocommerce' );
		return $actions;
	}

	/** @param array<int, int|string> $ids */
	public function handle_bulk_action( string $redirect_to, string $action, array $ids ): string {
		if ( 'soocool_download_labels' !== $action ) {
			return $redirect_to;
		}

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $order_ids ) {
			return $redirect_to;
		}

		return wp_nonce_url(
			admin_url( 'admin-post.php?action=soocool_download_label&order_ids=' . rawurlencode( implode( ',', $order_ids ) ) ),
			'soocool_download_labels_bulk'
		);
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

		$has_bulk_good_request = $this->has_bulk_good_ids_request();
		$good_ids              = $this->request_good_ids();
		if ( $has_bulk_good_request ) {
			check_admin_referer( 'soocool_download_good_labels_bulk' );
			if ( array() === $good_ids ) {
				wp_die( esc_html__( 'No valid SooCool good IDs selected for label download.', 'soocool-for-woocommerce' ) );
			}

			try {
				$pdf = $this->labels->get_bulk_good_labels( $good_ids, $output );
			} catch ( \Throwable $exception ) {
				wp_die( esc_html__( 'SooCool bulk good label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
			}

			$filename = count( $good_ids ) > 1 ? 'soocool-good-labels.pdf' : 'soocool-good-label-' . absint( $good_ids[0] ) . '.pdf';
			$this->send_pdf( $pdf, $filename );
		}

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

	private function has_bulk_good_ids_request(): bool {
		return null !== filter_input( INPUT_GET, 'good_ids', FILTER_UNSAFE_RAW );
	}

	/** @return array<int, int> */
	private function request_good_ids(): array {
		$good_ids = sanitize_text_field( (string) filter_input( INPUT_GET, 'good_ids', FILTER_UNSAFE_RAW ) );
		if ( '' === $good_ids ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', $good_ids ) as $good_id ) {
			$id = absint( $good_id );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	private function has_bulk_order_ids_request(): bool {
		return null !== filter_input( INPUT_GET, 'order_ids', FILTER_UNSAFE_RAW );
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
