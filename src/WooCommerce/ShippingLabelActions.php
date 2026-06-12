<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ShippingLabelActions {

	private const MAX_BULK_LABEL_IDS = 50;
	private const BULK_ORDER_LABELS_ACTION = 'soocool_download_order_labels';
	private const BULK_GOOD_LABELS_ACTION  = 'soocool_download_good_labels';

	public function __construct( private readonly ShippingLabelService $labels, private readonly OptionRepository $options, private readonly OrderMeta $meta ) {}

	public function register(): void {
		add_action( 'admin_post_soocool_download_label', array( $this, 'download' ) );

		// Bulk label downloads in both the HPOS and legacy order list tables.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_bulk_action( array $actions ): array {
		$actions[ self::BULK_ORDER_LABELS_ACTION ] = __( 'Download SooCool order labels', 'soocool-for-woocommerce' );
		$actions[ self::BULK_GOOD_LABELS_ACTION ]  = __( 'Download SooCool good labels', 'soocool-for-woocommerce' );
		return $actions;
	}

	/** @param array<int, int|string> $ids */
	public function handle_bulk_action( string $redirect_to, string $action, array $ids ): string {
		if ( ! in_array( $action, array( self::BULK_ORDER_LABELS_ACTION, self::BULK_GOOD_LABELS_ACTION ), true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return add_query_arg( 'soocool_label_error', 'permission', $redirect_to );
		}

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( array() === $order_ids ) {
			return add_query_arg( 'soocool_label_error', 'empty', $redirect_to );
		}

		if ( count( $order_ids ) > self::MAX_BULK_LABEL_IDS ) {
			wp_die( esc_html__( 'Select 50 or fewer orders for one SooCool label download.', 'soocool-for-woocommerce' ) );
		}

		// Stream directly from the verified bulk-action request. Redirecting to a
		// second admin-post URL with a freshly generated nonce can fail in the HPOS
		// orders table and show "link expired" before the PDF is downloaded.
		$output = $this->requested_output();
		if ( self::BULK_GOOD_LABELS_ACTION === $action ) {
			$this->send_bulk_good_labels_for_orders( $order_ids, $output );
			return $redirect_to;
		}

		$this->send_bulk_order_labels_for_orders( $order_ids, $output );
		return $redirect_to;
	}

	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to download this label.', 'soocool-for-woocommerce' ) );
		}

		$output = $this->requested_output();

		if ( $this->has_order_good_ids_request() ) {
			$this->handle_order_good_download( $output );
			return;
		}

		if ( $this->has_unbound_bulk_download_request() ) {
			wp_die( esc_html__( 'Use the WooCommerce bulk action or an order-specific SooCool label link for label downloads.', 'soocool-for-woocommerce' ) );
		}

		$this->handle_single_label_download( $output );
	}

	private function requested_output(): string {
		$requested_output = sanitize_key( (string) filter_input( INPUT_GET, 'output', FILTER_UNSAFE_RAW ) );
		if ( '' === $requested_output ) {
			$settings         = $this->options->all();
			$requested_output = (string) $settings['label_output'];
		}

		return 'collated_a4' === $requested_output ? 'collated_a4' : 'a6';
	}


	private function handle_order_good_download( string $output ): void {
		$order_id = absint( filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT ) ?: 0 );
		check_admin_referer( 'soocool_download_good_labels_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'soocool-for-woocommerce' ) );
		}

		$requested_good_ids = $this->request_good_ids();
		$stored_good_ids    = $this->stored_good_ids( $order );
		$unknown_good_ids   = array_diff( $requested_good_ids, $stored_good_ids );

		if ( array() === $requested_good_ids || array() !== $unknown_good_ids ) {
			wp_die( esc_html__( 'One or more requested SooCool good IDs do not belong to this order.', 'soocool-for-woocommerce' ) );
		}

		if ( count( $requested_good_ids ) > self::MAX_BULK_LABEL_IDS ) {
			wp_die( esc_html__( 'Select 50 or fewer SooCool good IDs for one label download.', 'soocool-for-woocommerce' ) );
		}

		$good_ids = $requested_good_ids;

		try {
			$pdf = count( $good_ids ) > 1
				? $this->labels->get_bulk_good_labels( $good_ids, $output )
				: $this->labels->get_good_label( $order, $good_ids[0], $output );
		} catch ( \Throwable $exception ) {
			wp_die( esc_html__( 'SooCool good label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
		}

		$filename = count( $good_ids ) > 1 ? 'soocool-good-labels-' . absint( $order_id ) . '.pdf' : 'soocool-label-' . absint( $order_id ) . '-good-' . absint( $good_ids[0] ) . '.pdf';
		$this->send_pdf( $pdf, $filename );
	}

	/** @param array<int, int> $order_ids */
	private function send_bulk_order_labels_for_orders( array $order_ids, string $output ): void {
		$orders = $this->orders_from_ids( $order_ids );
		if ( array() === $orders ) {
			wp_die( esc_html__( 'No valid orders selected for SooCool label download.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = $this->labels->get_bulk_labels( $orders, $output );
		} catch ( \Throwable $exception ) {
			wp_die( esc_html__( 'SooCool bulk order label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
		}

		$filename = count( $orders ) > 1 ? 'soocool-order-labels.pdf' : 'soocool-order-label-' . absint( $orders[0]->get_id() ) . '.pdf';
		$this->send_pdf( $pdf, $filename );
	}

	/** @param array<int, int> $order_ids */
	private function send_bulk_good_labels_for_orders( array $order_ids, string $output ): void {
		$good_ids = array();
		foreach ( $this->orders_from_ids( $order_ids ) as $order ) {
			foreach ( $this->meta->get_good_ids( $order ) as $good_id ) {
				$good_ids[] = $good_id;
			}
		}

		$good_ids = array_values( array_unique( array_filter( array_map( 'absint', $good_ids ) ) ) );
		if ( array() === $good_ids ) {
			wp_die( esc_html__( 'No SooCool good IDs found for the selected orders.', 'soocool-for-woocommerce' ) );
		}

		if ( count( $good_ids ) > self::MAX_BULK_LABEL_IDS ) {
			wp_die( esc_html__( 'The selected orders contain more than 50 SooCool good IDs. Select fewer orders and try again.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = $this->labels->get_bulk_good_labels( $good_ids, $output );
		} catch ( \Throwable $exception ) {
			wp_die( esc_html__( 'SooCool bulk good label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
		}

		$filename = count( $good_ids ) > 1 ? 'soocool-good-labels.pdf' : 'soocool-good-label-' . absint( $good_ids[0] ) . '.pdf';
		$this->send_pdf( $pdf, $filename );
	}

	/** @param array<int, int> $order_ids @return array<int, WC_Order> */
	private function orders_from_ids( array $order_ids ): array {
		$orders = array();
		foreach ( $order_ids as $selected_order_id ) {
			$order = wc_get_order( $selected_order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$orders[] = $order;
		}

		return $orders;
	}

	private function handle_single_label_download( string $output ): void {
		$order_id = absint( filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT ) ?: 0 );
		check_admin_referer( 'soocool_download_label_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'soocool-for-woocommerce' ) );
		}

		$good_id = absint( filter_input( INPUT_GET, 'good_id', FILTER_VALIDATE_INT ) ?: 0 );
		if ( $good_id > 0 && ! in_array( $good_id, $this->stored_good_ids( $order ), true ) ) {
			wp_die( esc_html__( 'The requested SooCool good ID does not belong to this order.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = $good_id > 0 ? $this->labels->get_good_label( $order, $good_id, $output ) : $this->labels->get_label( $order, $output );
		} catch ( \Throwable $exception ) {
			wp_die( esc_html__( 'SooCool label download failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' ) );
		}

		$filename = $good_id > 0 ? 'soocool-label-' . absint( $order_id ) . '-good-' . absint( $good_id ) . '.pdf' : 'soocool-label-' . absint( $order_id ) . '.pdf';
		$this->send_pdf( $pdf, $filename );
	}

	/** @return array<int, int> */
	private function stored_good_ids( WC_Order $order ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', $this->meta->get_good_ids( $order ) ) ) ) );
	}

	private function has_order_good_ids_request(): bool {
		return null !== filter_input( INPUT_GET, 'good_ids', FILTER_UNSAFE_RAW ) && null !== filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT );
	}

	/** @return array<int, int> */
	private function request_good_ids(): array {
		$good_ids = sanitize_text_field( (string) filter_input( INPUT_GET, 'good_ids', FILTER_UNSAFE_RAW ) );
		if ( '' === $good_ids ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', $good_ids ) as $good_id ) {
			$good_id = trim( $good_id );
			if ( '' === $good_id || ! ctype_digit( $good_id ) || 0 >= (int) $good_id ) {
				return array();
			}
			$ids[] = (int) $good_id;
		}

		return array_values( array_unique( $ids ) );
	}

	private function has_unbound_bulk_download_request(): bool {
		return null !== filter_input( INPUT_GET, 'order_ids', FILTER_UNSAFE_RAW )
			|| ( null !== filter_input( INPUT_GET, 'good_ids', FILTER_UNSAFE_RAW ) && null === filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT ) );
	}

	private function send_pdf( string $pdf, string $filename ): void {
		if ( '' === $pdf || ! str_starts_with( ltrim( $pdf ), '%PDF' ) ) {
			wp_die( esc_html__( 'SooCool did not return a valid PDF label.', 'soocool-for-woocommerce' ) );
		}

		if ( headers_sent() ) {
			wp_die( esc_html__( 'SooCool label download could not start because output was already sent.', 'soocool-for-woocommerce' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
		exit;
	}
}
