<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class ApiClient {

	private readonly ApiTransport $transport;

	private readonly ApiPdfDownloader $pdf_downloader;

	private const MAX_BULK_LABEL_IDS      = 50;

	public function __construct( private readonly OptionRepository $options, private readonly Logger $logger, private readonly ApiErrorMapper $errors ) {
		$this->transport      = new ApiTransport( $this->options, $this->logger, $this->errors );
		$this->pdf_downloader = new ApiPdfDownloader( $this->options, $this->errors, $this->transport );
	}

	public function ping(): ApiResponse {
		return $this->transport->request( 'GET', '/ping' );
	}

	/** @param array<string, mixed> $payload */
	public function create_order( array $payload ): ApiResponse {
		return $this->transport->request( 'POST', '/order', $payload );
	}

	public function search_order_by_reference( string $order_reference ): ApiResponse {
		$order_reference = trim( sanitize_text_field( $order_reference ) );
		if ( '' === $order_reference ) {
			throw new ApiException( __( 'SooCool orderreferentie ontbreekt.', 'soocool-for-woocommerce' ), 0 );
		}

		return $this->transport->request( 'GET', '/order?orderReference=' . rawurlencode( $order_reference ) );
	}

	public function get_order( int|string $order_id ): ApiResponse {
		return $this->transport->request( 'GET', '/order/' . $this->encode_numeric_order_id( $order_id ) );
	}

	/** @param array<string, mixed> $payload */
	public function update_order( int|string $order_id, array $payload ): ApiResponse {
		return $this->transport->request( 'PUT', '/order/' . $this->encode_numeric_order_id( $order_id ), $payload );
	}

	public function cancel_order( int|string $order_id ): ApiResponse {
		return $this->transport->request( 'DELETE', '/order/' . $this->encode_numeric_order_id( $order_id ) );
	}

	/** @deprecated 0.5.98 Use download_shipping_label() to avoid retaining PDF data in memory. */
	public function get_shipping_label( int|string $order_id, string $output = 'a6' ): ApiResponse {
		return $this->legacy_pdf_response( $this->download_shipping_label( $order_id, $output ) );
	}

	/** @deprecated 0.5.98 Use download_good_shipping_label() to avoid retaining PDF data in memory. */
	public function get_good_shipping_label( int|string $order_id, int|string $good_id, string $output = 'a6' ): ApiResponse {
		return $this->legacy_pdf_response( $this->download_good_shipping_label( $order_id, $good_id, $output ) );
	}

	/**
	 * @param array<int, int|string> $order_ids
	 * @deprecated 0.5.98 Use download_multiple_shipping_labels() to avoid retaining PDF data in memory.
	 */
	public function get_multiple_shipping_labels( array $order_ids, string $output = 'a6' ): ApiResponse {
		return $this->legacy_pdf_response( $this->download_multiple_shipping_labels( $order_ids, $output ) );
	}

	/**
	 * @param array<int, int|string> $good_ids
	 * @deprecated 0.5.98 Use download_multiple_good_shipping_labels() to avoid retaining PDF data in memory.
	 */
	public function get_multiple_good_shipping_labels( array $good_ids, string $output = 'a6' ): ApiResponse {
		return $this->legacy_pdf_response( $this->download_multiple_good_shipping_labels( $good_ids, $output ) );
	}

	public function download_shipping_label( int|string $order_id, string $output = 'a6' ): string {
		$output = $this->normalize_label_output( $output );
		return $this->pdf_downloader->download( '/order/' . $this->encode_numeric_order_id( $order_id ) . '/shipping-label?output=' . rawurlencode( $output ) );
	}

	public function download_good_shipping_label( int|string $order_id, int|string $good_id, string $output = 'a6' ): string {
		$output = $this->normalize_label_output( $output );
		return $this->pdf_downloader->download( '/order/' . $this->encode_numeric_order_id( $order_id ) . '/good/' . rawurlencode( $this->encode_numeric_good_id( $good_id ) ) . '/shipping-label?output=' . rawurlencode( $output ) );
	}

	/** @param array<int, int|string> $order_ids */
	public function download_multiple_shipping_labels( array $order_ids, string $output = 'a6' ): string {
		$ids = array_values( array_unique( array_map( array( $this, 'encode_numeric_order_id' ), $order_ids ) ) );
		if ( array() === $ids || count( $ids ) > self::MAX_BULK_LABEL_IDS ) {
			throw new ApiException( __( 'Selecteer tussen één en 50 geldige SooCool order-ID’s voor één labeldownload.', 'soocool-for-woocommerce' ), 0 );
		}

		$output = $this->normalize_label_output( $output );
		return $this->pdf_downloader->download( '/shipping-label?orderIds=' . implode( ',', array_map( 'rawurlencode', $ids ) ) . '&output=' . rawurlencode( $output ) );
	}

	/** @param array<int, int|string> $good_ids */
	public function download_multiple_good_shipping_labels( array $good_ids, string $output = 'a6' ): string {
		$ids = array_values( array_unique( array_map( array( $this, 'encode_numeric_good_id' ), $good_ids ) ) );
		if ( array() === $ids || count( $ids ) > self::MAX_BULK_LABEL_IDS ) {
			throw new ApiException( __( 'Selecteer tussen één en 50 geldige SooCool-goederen-ID’s voor één labeldownload.', 'soocool-for-woocommerce' ), 0 );
		}

		$output = $this->normalize_label_output( $output );
		return $this->pdf_downloader->download( '/shipping-label?goodIds=' . implode( ',', array_map( 'rawurlencode', $ids ) ) . '&output=' . rawurlencode( $output ) );
	}

	private function legacy_pdf_response( string $filename ): ApiResponse {
		try {
			$size = is_file( $filename ) ? filesize( $filename ) : false;
			if ( false === $size || $size < 8 || $size > ApiTransport::MAX_PDF_RESPONSE_BYTES ) {
				throw new ApiException( __( 'Het SooCool-label is te groot, onvolledig of ontbreekt.', 'soocool-for-woocommerce' ), 502 );
			}

			$body = file_get_contents( $filename );
			if ( false === $body || strlen( $body ) !== $size ) {
				throw new ApiException( __( 'Het SooCool-label kon niet volledig worden gelezen.', 'soocool-for-woocommerce' ), 502 );
			}

			return new ApiResponse( 200, $body, array( 'content-type' => 'application/pdf' ) );
		} finally {
			wp_delete_file( $filename );
		}
	}

	private function normalize_label_output( string $output ): string {
		return 'collated_a4' === $output ? 'collated_a4' : 'a6';
	}

	private function encode_numeric_order_id( int|string $order_id ): string {
		$id = NumericIdentifier::positive( sanitize_text_field( (string) $order_id ) );
		if ( null === $id ) {
			throw new ApiException( __( 'Geldige numerieke SooCool order-ID ontbreekt.', 'soocool-for-woocommerce' ), 0 );
		}

		return (string) $id;
	}

	private function encode_numeric_good_id( int|string $good_id ): string {
		$id = NumericIdentifier::positive( sanitize_text_field( (string) $good_id ) );
		if ( null === $id ) {
			throw new ApiException( __( 'Geldig numeriek SooCool-goederen-ID ontbreekt.', 'soocool-for-woocommerce' ), 0 );
		}

		return (string) $id;
	}

}
