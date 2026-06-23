<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Domain\ShippingLabelService;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelActions {

	private const MAX_BULK_LABEL_IDS        = 50;
	private const BULK_ORDER_LABELS_ACTION  = 'soocool_download_order_labels';
	private const BULK_GOOD_LABELS_ACTION   = 'soocool_download_good_labels';
	private const BULK_LABEL_DOWNLOAD_ACTION = 'soocool_download_bulk_labels';

	public function __construct(
		private readonly ShippingLabelService $labels,
		private readonly OptionRepository $options,
		private readonly ShippingLabelBulkTokenStore $bulk_tokens,
		private readonly ShippingLabelOrderResolver $order_resolver,
		private readonly ShippingLabelPdfResponse $pdf_response
	) {}

	public function register(): void {
		add_action( 'admin_post_soocool_download_label', array( $this, 'download' ) );
		add_action( 'admin_post_' . self::BULK_LABEL_DOWNLOAD_ACTION, array( $this, 'download_bulk_labels' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_bulk_action' ), 10, 3 );
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_bulk_action( array $actions ): array {
		$actions[ self::BULK_ORDER_LABELS_ACTION ] = __( 'SooCool orderlabels downloaden', 'soocool-for-woocommerce' );
		$actions[ self::BULK_GOOD_LABELS_ACTION ]  = __( 'SooCool goederenlabels downloaden', 'soocool-for-woocommerce' );
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
			wp_die( esc_html__( 'Selecteer maximaal 50 orders voor één SooCool labeldownload.', 'soocool-for-woocommerce' ) );
		}

		$token = $this->bulk_tokens->create( $action, $order_ids, $this->requested_output() );
		if ( '' === $token ) {
			return add_query_arg( 'soocool_label_error', 'token', $redirect_to );
		}

		$download_url = add_query_arg(
			array(
				'action' => self::BULK_LABEL_DOWNLOAD_ACTION,
				'token'  => $token,
			),
			admin_url( 'admin-post.php' )
		);

		return add_query_arg( '_wpnonce', wp_create_nonce( $this->bulk_tokens->nonce_action( $token ) ), $download_url );
	}

	public function download_bulk_labels(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je mag deze labels niet downloaden.', 'soocool-for-woocommerce' ) );
		}

		$token = sanitize_key( $this->query_string( 'token' ) );
		if ( '' === $token ) {
			wp_die( esc_html__( 'SooCool labeldownloadverzoek is ongeldig.', 'soocool-for-woocommerce' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( $this->bulk_tokens->nonce_action( $token ) );
		$payload = $this->bulk_tokens->consume( $token );
		if ( array() === $payload || (int) ( $payload['user_id'] ?? 0 ) !== get_current_user_id() ) {
			wp_die( esc_html__( 'SooCool labeldownloadverzoek is verlopen.', 'soocool-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$action    = sanitize_key( (string) ( $payload['action'] ?? '' ) );
		$order_ids = isset( $payload['order_ids'] ) && is_array( $payload['order_ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $payload['order_ids'] ) ) ) ) : array();
		$output    = 'collated_a4' === (string) ( $payload['output'] ?? '' ) ? 'collated_a4' : 'a6';
		if ( array() === $order_ids || count( $order_ids ) > self::MAX_BULK_LABEL_IDS ) {
			wp_die( esc_html__( 'SooCool labeldownloadverzoek is ongeldig.', 'soocool-for-woocommerce' ), '', array( 'response' => 400 ) );
		}

		if ( self::BULK_GOOD_LABELS_ACTION === $action ) {
			$this->send_bulk_good_labels_for_orders( $order_ids, $output );
			return;
		}

		if ( self::BULK_ORDER_LABELS_ACTION === $action ) {
			$this->send_bulk_order_labels_for_orders( $order_ids, $output );
			return;
		}

		wp_die( esc_html__( 'SooCool labeldownloadactie is ongeldig.', 'soocool-for-woocommerce' ), '', array( 'response' => 400 ) );
	}

	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je mag dit label niet downloaden.', 'soocool-for-woocommerce' ) );
		}

		$output = $this->requested_output();
		if ( $this->has_order_good_ids_request() ) {
			$this->handle_order_good_download( $output );
			return;
		}

		if ( $this->has_unbound_bulk_download_request() ) {
			wp_die( esc_html__( 'Gebruik de WooCommerce bulkactie of een order-specifieke SooCool labellink voor labeldownloads.', 'soocool-for-woocommerce' ) );
		}

		$this->handle_single_label_download( $output );
	}

	private function requested_output(): string {
		$requested_output = sanitize_key( $this->query_string( 'output' ) );
		if ( '' === $requested_output ) {
			$settings         = $this->options->all();
			$requested_output = (string) $settings['label_output'];
		}

		return 'collated_a4' === $requested_output ? 'collated_a4' : 'a6';
	}

	private function handle_order_good_download( string $output ): void {
		$order_id = $this->query_int( 'order_id' );
		check_admin_referer( 'soocool_download_good_labels_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order niet gevonden.', 'soocool-for-woocommerce' ) );
		}

		$requested_good_ids = $this->request_good_ids();
		$stored_good_ids    = $this->order_resolver->stored_good_ids( $order );
		if ( array() === $requested_good_ids || array() !== array_diff( $requested_good_ids, $stored_good_ids ) ) {
			wp_die( esc_html__( 'Eén of meer aangevraagde SooCool-goederen-ID’s horen niet bij deze order.', 'soocool-for-woocommerce' ) );
		}

		if ( count( $requested_good_ids ) > self::MAX_BULK_LABEL_IDS ) {
			wp_die( esc_html__( 'Selecteer maximaal 50 SooCool-goederen-ID’s voor één labeldownload.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = count( $requested_good_ids ) > 1
				? $this->labels->get_bulk_good_labels( $requested_good_ids, $output )
				: $this->labels->get_good_label( $order, $requested_good_ids[0], $output );
		} catch ( \Throwable ) {
			wp_die( esc_html__( 'SooCool goederenlabeldownload mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) );
		}

		$filename = count( $requested_good_ids ) > 1 ? 'soocool-good-labels-' . absint( $order_id ) . '.pdf' : 'soocool-label-' . absint( $order_id ) . '-good-' . absint( $requested_good_ids[0] ) . '.pdf';
		$this->pdf_response->send( $pdf, $filename );
	}

	/** @param array<int, int> $order_ids */
	private function send_bulk_order_labels_for_orders( array $order_ids, string $output ): void {
		$orders = $this->order_resolver->orders_from_ids( $order_ids );
		if ( array() === $orders ) {
			wp_die( esc_html__( 'Geen geldige orders geselecteerd voor SooCool labeldownload.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = $this->labels->get_bulk_labels( $orders, $output );
		} catch ( \Throwable ) {
			wp_die( esc_html__( 'SooCool bulkdownload van orderlabels mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) );
		}

		$filename = count( $orders ) > 1 ? 'soocool-order-labels.pdf' : 'soocool-order-label-' . absint( $orders[0]->get_id() ) . '.pdf';
		$this->pdf_response->send( $pdf, $filename );
	}

	/** @param array<int, int> $order_ids */
	private function send_bulk_good_labels_for_orders( array $order_ids, string $output ): void {
		$orders   = $this->order_resolver->orders_from_ids( $order_ids );
		$good_ids = $this->order_resolver->good_ids_from_orders( $orders );
		if ( array() === $good_ids ) {
			wp_die( esc_html__( 'Geen SooCool-goederen-ID’s gevonden voor de geselecteerde orders.', 'soocool-for-woocommerce' ) );
		}

		if ( count( $good_ids ) > self::MAX_BULK_LABEL_IDS ) {
			wp_die( esc_html__( 'De geselecteerde orders bevatten meer dan 50 SooCool-goederen-ID’s. Selecteer minder orders en probeer opnieuw.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = 1 === count( $orders ) && 1 === count( $good_ids )
				? $this->labels->get_good_label( $orders[0], $good_ids[0], $output )
				: $this->labels->get_bulk_good_labels( $good_ids, $output );
		} catch ( \Throwable ) {
			wp_die( esc_html__( 'SooCool bulkdownload van goederenlabels mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) );
		}

		$filename = count( $good_ids ) > 1 ? 'soocool-good-labels.pdf' : 'soocool-good-label-' . preg_replace( '/[^0-9-]/', '', (string) $good_ids[0] ) . '.pdf';
		$this->pdf_response->send( $pdf, $filename );
	}

	private function handle_single_label_download( string $output ): void {
		$order_id = $this->query_int( 'order_id' );
		check_admin_referer( 'soocool_download_label_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Order niet gevonden.', 'soocool-for-woocommerce' ) );
		}

		$good_id = $this->query_signed_int( 'good_id' );
		if ( null !== $good_id && ! in_array( $good_id, $this->order_resolver->stored_good_ids( $order ), true ) ) {
			wp_die( esc_html__( 'De gevraagde SooCool-goederen-ID hoort niet bij deze order.', 'soocool-for-woocommerce' ) );
		}

		try {
			$pdf = null !== $good_id ? $this->labels->get_good_label( $order, $good_id, $output ) : $this->labels->get_label( $order, $output );
		} catch ( \Throwable ) {
			wp_die( esc_html__( 'SooCool labeldownload mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) );
		}

		$filename = null !== $good_id ? 'soocool-label-' . absint( $order_id ) . '-good-' . preg_replace( '/[^0-9-]/', '', (string) $good_id ) . '.pdf' : 'soocool-label-' . absint( $order_id ) . '.pdf';
		$this->pdf_response->send( $pdf, $filename );
	}

	private function has_order_good_ids_request(): bool {
		return $this->query_has( 'good_ids' ) && $this->query_int( 'order_id' ) > 0;
	}

	/** @return array<int, int> */
	private function request_good_ids(): array {
		$good_ids = $this->query_string( 'good_ids' );
		if ( '' === $good_ids ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', $good_ids ) as $good_id ) {
			$good_id = trim( $good_id );
			if ( '' === $good_id || 1 !== preg_match( '/^-?\d+$/', $good_id ) || 0 === (int) $good_id ) {
				return array();
			}
			$ids[] = (int) $good_id;
		}

		return array_values( array_unique( $ids ) );
	}

	private function has_unbound_bulk_download_request(): bool {
		return $this->query_has( 'order_ids' ) || ( $this->query_has( 'good_ids' ) && 0 === $this->query_int( 'order_id' ) );
	}

	private function query_has( string $key ): bool {
		return isset( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only after capability and nonce gates.
	}

	private function query_string( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only after capability and nonce gates.
			return '';
		}

		return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only after capability and nonce gates.
	}

	private function query_int( string $key ): int {
		return absint( $this->query_string( $key ) );
	}

	private function query_signed_int( string $key ): ?int {
		$value = trim( $this->query_string( $key ) );
		if ( '' === $value || 1 !== preg_match( '/^-?\d+$/', $value ) || 0 === (int) $value ) {
			return null;
		}

		return (int) $value;
	}
}
