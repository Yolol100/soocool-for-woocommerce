<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class ApiPdfDownloader {

	public function __construct(
		private readonly OptionRepository $options,
		private readonly ApiErrorMapper $errors,
		private readonly ApiTransport $transport
	) {}

	public function download( string $path ): string {
		$api_key = trim( $this->options->api_key() );
		$url     = $this->options->base_url() . $path;
		if ( '' === $api_key ) {
			throw new ApiException( __( 'SooCool API-key ontbreekt of is ongeldig.', 'soocool-for-woocommerce' ), 401 );
		}

		$attempt = 0;
		do {
			++$attempt;
			$filename = wp_tempnam( 'soocool-label.pdf' );
			if ( ! is_string( $filename ) || '' === $filename ) {
				throw new ApiException( __( 'Kon geen veilig tijdelijk bestand voor het SooCool-label maken.', 'soocool-for-woocommerce' ), 500 );
			}

			$args = array(
				'method'              => 'GET',
				'timeout'             => ApiTransport::REQUEST_TIMEOUT_SECONDS,
				'redirection'         => 0,
				'limit_response_size' => ApiTransport::MAX_PDF_RESPONSE_BYTES + 1,
				'stream'              => true,
				'filename'            => $filename,
				'headers'             => array(
					'X-API-Key'  => $api_key,
					'Accept'     => 'application/pdf',
					'User-Agent' => 'SooCool for WooCommerce/' . SOOCOOL_VERSION . '; ' . home_url( '/' ),
				),
			);
			$response = wp_safe_remote_request( $url, $args );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$retry    = $attempt < ApiTransport::MAX_RETRY_ATTEMPTS && ( is_wp_error( $response ) || in_array( $status, ApiTransport::RETRYABLE_STATUS_CODES, true ) );
			if ( $retry ) {
				wp_delete_file( $filename );
				$delay_ms = $this->transport->retry_delay_milliseconds( $response, $attempt );
				if ( 0 < $delay_ms ) {
					usleep( $delay_ms * 1000 );
				}
				continue;
			}

			if ( is_wp_error( $response ) ) {
				wp_delete_file( $filename );
				throw new ApiException( __( 'Kon geen verbinding maken met de SooCool API.', 'soocool-for-woocommerce' ), 0, array(), true );
			}
			if ( $status < 200 || $status >= 300 ) {
				$raw    = is_readable( $filename ) ? (string) file_get_contents( $filename, false, null, 0, 65536 ) : '';
				$body   = $this->transport->decode_body( $raw );
				$errors = $this->errors->redacted_errors( $body );
				wp_delete_file( $filename );
				throw new ApiException( $this->errors->public_message( $status ), $status, $errors );
			}

			$this->validate_pdf_file( $filename );
			return $filename;
		} while ( $attempt < ApiTransport::MAX_RETRY_ATTEMPTS );

		throw new ApiException( __( 'SooCool-labeldownload is mislukt.', 'soocool-for-woocommerce' ), 502 );
	}

	private function validate_pdf_file( string $filename ): void {
		$handle = @fopen( $filename, 'rb' );
		if ( false === $handle ) {
			wp_delete_file( $filename );
			throw new ApiException( __( 'Het tijdelijke SooCool-label kon niet worden geopend.', 'soocool-for-woocommerce' ), 502 );
		}

		try {
			$stat = fstat( $handle );
			$size = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
			if ( $size < 8 || $size > ApiTransport::MAX_PDF_RESPONSE_BYTES ) {
				throw new ApiException( __( 'SooCool gaf een te groot of onvolledig PDF-label terug.', 'soocool-for-woocommerce' ), 502 );
			}
			$header = fread( $handle, 5 );
			if ( '%PDF-' !== $header ) {
				throw new ApiException( __( 'SooCool gaf geen geldig PDF-label terug.', 'soocool-for-woocommerce' ), 502 );
			}
			fseek( $handle, max( 0, $size - 1024 ) );
			$tail = stream_get_contents( $handle );
			if ( ! is_string( $tail ) || false === strpos( $tail, '%%EOF' ) ) {
				throw new ApiException( __( 'SooCool gaf een onvolledig PDF-label terug.', 'soocool-for-woocommerce' ), 502 );
			}
		} catch ( \Throwable $exception ) {
			fclose( $handle );
			wp_delete_file( $filename );
			throw $exception;
		}

		fclose( $handle );
	}

}
