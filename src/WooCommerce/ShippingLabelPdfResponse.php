<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelPdfResponse {

	public function send( string $pdf, string $filename ): void {
		if ( '' === $pdf || ! str_starts_with( ltrim( $pdf ), '%PDF' ) ) {
			wp_die( esc_html__( 'SooCool gaf geen geldig PDF-label terug.', 'soocool-for-woocommerce' ) );
		}

		if ( headers_sent() ) {
			wp_die( esc_html__( 'SooCool labeldownload kon niet starten omdat er al output is verstuurd.', 'soocool-for-woocommerce' ), '', array( 'response' => 500 ) );
		}

		while ( ob_get_level() > 0 ) {
			$status = ob_get_status();
			if ( ! is_array( $status ) || empty( $status['del'] ) ) {
				break;
			}
			ob_end_clean();
		}

		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'soocool-label.pdf';
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
		exit;
	}
}
