<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelPdfResponse {

	public function send( string $pdf, string $filename ): void {
		$temp = wp_tempnam( 'soocool-label.pdf' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			wp_die( esc_html__( 'SooCool labeldownload kon niet veilig worden voorbereid.', 'soocool-for-woocommerce' ), '', array( 'response' => 500 ) );
		}

		$written = @file_put_contents( $temp, $pdf, LOCK_EX );
		if ( false === $written || strlen( $pdf ) !== $written ) {
			wp_delete_file( $temp );
			wp_die( esc_html__( 'SooCool labeldownload kon niet veilig worden voorbereid.', 'soocool-for-woocommerce' ), '', array( 'response' => 500 ) );
		}
		$this->send_file( $temp, $filename );
	}

	public function send_file( string $path, string $filename ): void {
		$real_path = realpath( $path );
		if ( false === $real_path || ! is_file( $real_path ) || is_link( $path ) ) {
			wp_die( esc_html__( 'SooCool gaf geen geldig PDF-label terug.', 'soocool-for-woocommerce' ) );
		}

		$handle = @fopen( $real_path, 'rb' );
		if ( false === $handle ) {
			wp_delete_file( $real_path );
			wp_die( esc_html__( 'SooCool labeldownload kon niet worden geopend.', 'soocool-for-woocommerce' ), '', array( 'response' => 500 ) );
		}

		$valid = false;
		$size  = 0;
		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
			if ( $size >= 8 && $size <= 26214400 && '%PDF-' === fread( $handle, 5 ) ) {
				fseek( $handle, max( 0, $size - 1024 ) );
				$tail  = stream_get_contents( $handle );
				$valid = is_string( $tail ) && false !== strpos( $tail, '%%EOF' );
			}
			if ( ! $valid || headers_sent() ) {
				throw new \RuntimeException( 'invalid_pdf_response' );
			}

			while ( ob_get_level() > 0 ) {
				$status = ob_get_status();
				if ( ! is_array( $status ) || empty( $status['del'] ) ) {
					break;
				}
				ob_end_clean();
			}

			$filename = sanitize_file_name( $filename );
			$filename = '' !== $filename ? $filename : 'soocool-label.pdf';
			rewind( $handle );
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: application/pdf' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . $size );

			$sent = 0;
			while ( $sent < $size && ! feof( $handle ) ) {
				$chunk = fread( $handle, min( 8192, $size - $sent ) );
				if ( false === $chunk || '' === $chunk ) {
					break;
				}
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF output.
				$sent += strlen( $chunk );
			}
			if ( $sent !== $size ) {
				do_action( 'soocool_pdf_stream_failed', $real_path, $sent, $size );
			}
		} catch ( \Throwable ) {
			fclose( $handle );
			wp_delete_file( $real_path );
			wp_die( esc_html__( 'SooCool gaf geen volledig geldig PDF-label terug.', 'soocool-for-woocommerce' ), '', array( 'response' => 500 ) );
		}

		fclose( $handle );
		wp_delete_file( $real_path );
		exit;
	}

}
