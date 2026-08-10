<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class ApiTransport {

	public const RETRYABLE_STATUS_CODES = array( 429, 502, 503, 504 );
	public const REQUEST_TIMEOUT_SECONDS = 10;
	public const MAX_RETRY_ATTEMPTS = 2;
	public const MAX_JSON_RESPONSE_BYTES = 2097152;
	public const MAX_PDF_RESPONSE_BYTES = 26214400;

	public function __construct(
		private readonly OptionRepository $options,
		private readonly Logger $logger,
		private readonly ApiErrorMapper $errors
	) {}

	public function request( string $method, string $path, ?array $payload = null, array $extra_headers = array() ): ApiResponse {
		$api_key = trim( $this->options->api_key() );
		$url     = $this->options->base_url() . $path;
		if ( '' === $api_key ) {
			$this->logger->error(
				'SooCool API key is missing or invalid before request.',
				$this->api_key_debug_context( $api_key, $url, $path )
			);
			throw new ApiException( __( 'SooCool API-key ontbreekt of is ongeldig. Plak en bewaar de API-key opnieuw.', 'soocool-for-woocommerce' ), 401 );
		}

		$headers = array_merge(
			array(
				'X-API-Key'  => $api_key,
				'Accept'     => 'application/json',
				'User-Agent' => 'SooCool for WooCommerce/' . SOOCOOL_VERSION . '; ' . home_url( '/' ),
			),
			$extra_headers
		);

		$expects_pdf = isset( $headers['Accept'] ) && str_contains( strtolower( (string) $headers['Accept'] ), 'application/pdf' );
		$response_limit = $expects_pdf ? self::MAX_PDF_RESPONSE_BYTES : self::MAX_JSON_RESPONSE_BYTES;

		$args = array(
			'method'              => $method,
			'timeout'             => self::REQUEST_TIMEOUT_SECONDS,
			'redirection'         => 0,
			'limit_response_size' => $response_limit + 1,
			'headers'             => $headers,
		);

		if ( null !== $payload ) {
			$json = wp_json_encode( $payload );
			if ( false === $json ) {
				throw new ApiException( __( 'Kon de SooCool payload niet coderen.', 'soocool-for-woocommerce' ), 0 );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = $json;
		}

		$response = $this->remote_request_with_retry( $method, $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'SooCool request failed.',
				array(
					'method' => $method,
					'path'   => $this->log_path( $path ),
					'error'  => $response->get_error_message(),
				)
			);
			throw new ApiException( __( 'Kon geen verbinding maken met de SooCool API. Probeer opnieuw of controleer de SooCool logs.', 'soocool-for-woocommerce' ), 0, array(), true );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$raw          = (string) wp_remote_retrieve_body( $response );
		$headers      = wp_remote_retrieve_headers( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );

		if ( strlen( $raw ) > $response_limit ) {
			$this->logger->error(
				'SooCool API response reached the configured size limit.',
				array(
					'method' => $method,
					'path'   => $this->log_path( $path ),
					'status' => $status,
					'limit'  => $response_limit,
				)
			);
			throw new ApiException( __( 'SooCool gaf een te grote of afgebroken response terug.', 'soocool-for-woocommerce' ), 502 );
		}

		$has_pdf_signature = str_starts_with( ltrim( $raw ), '%PDF-' );
		$is_pdf            = $expects_pdf && $has_pdf_signature;
		$allows_plain_text = 'GET' === $method && '/ping' === $path;
		$json_expected     = ! $expects_pdf && ( ! $allows_plain_text || str_contains( $content_type, 'json' ) );
		$json_invalid      = false;
		if ( $is_pdf ) {
			$body = $raw;
		} elseif ( $json_expected && 204 === $status ) {
			$body         = $this->decode_body( $raw );
			$json_invalid = '' !== trim( $raw );
		} elseif ( $json_expected ) {
			$body         = json_decode( $raw, true );
			$json_invalid = JSON_ERROR_NONE !== json_last_error();
		} else {
			$body = $this->decode_body( $raw );
		}

		if ( $status < 200 || $status >= 300 ) {
			$errors  = $this->errors->redacted_errors( $body );
			$trace_id = $this->errors->trace_id( $body );
			$message = $this->errors->public_message( $status );
			$context = array_merge(
				array(
					'method' => $method,
					'path'   => $this->log_path( $path ),
					'status' => $status,
					'errors' => $errors,
				),
				$this->api_key_debug_context( $api_key, $url, $path )
			);
			if ( '' !== $trace_id ) {
				$context['traceId'] = $trace_id;
			}
			$this->logger->error( 'SooCool API error.', $context );
			throw new ApiException( $message, absint( $status ), $errors );
		}

		if ( $json_invalid ) {
			$this->logger->error(
				'SooCool API returned malformed JSON.',
				array(
					'method'       => $method,
					'path'         => $this->log_path( $path ),
					'status'       => $status,
					'content_type' => sanitize_text_field( $content_type ),
				)
			);
			throw new ApiException( __( 'SooCool gaf een ongeldige JSON-response terug.', 'soocool-for-woocommerce' ), 502 );
		}

		if ( $expects_pdf && ( ! $is_pdf || '' === $raw ) ) {
			$this->logger->error(
				'SooCool label endpoint returned a non-PDF response.',
				array(
					'method'       => $method,
					'path'         => $this->log_path( $path ),
					'status'       => $status,
					'content_type' => sanitize_text_field( $content_type ),
				)
			);
			throw new ApiException( __( 'SooCool gaf geen geldig PDF-label terug.', 'soocool-for-woocommerce' ), 502 );
		}

		$this->logger->info(
			'SooCool API request completed.',
			array(
				'method' => $method,
				'path'   => $this->log_path( $path ),
				'status' => $status,
			)
		);
		return new ApiResponse( $status, $body, $this->response_headers_to_array( $headers ) );
	}

	private function response_headers_to_array( mixed $headers ): array {
		if ( is_array( $headers ) ) {
			return $headers;
		}

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$all = $headers->getAll();
			return is_array( $all ) ? $all : array();
		}

		if ( $headers instanceof \Traversable ) {
			return iterator_to_array( $headers );
		}

		return array();
	}

	private function api_key_debug_context( string $api_key, string $url, string $path ): array {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = is_string( $host ) ? $host : '';

		return array(
			'api_key_present' => '' !== $api_key,
			'api_key_source'  => $this->options->api_key_source(),
			'api_key_status'  => $this->options->api_key_status(),
			'api_key_length'  => strlen( $api_key ),
			'header_name_sent' => 'X-API-Key',
			'request_url_host' => $host,
			'request_path'     => $this->log_path( $path ),
		);
	}

	private function log_path( string $path ): string {
		$query_position = strpos( $path, '?' );
		$path_only      = false === $query_position ? $path : substr( $path, 0, $query_position );

		return sanitize_text_field( $path_only );
	}

	public function decode_body( string $raw ): mixed {
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : $raw;
	}

	private function remote_request_with_retry( string $method, string $url, array $args ): array|\WP_Error {
		$attempts = 0;
		$response = null;
		$method    = strtoupper( $method );
		$may_retry = in_array( $method, array( 'GET', 'HEAD' ), true );

		do {
			++$attempts;
			$response     = wp_safe_remote_request( $url, $args );
			$status       = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$should_retry = $may_retry && $attempts < self::MAX_RETRY_ATTEMPTS && ( is_wp_error( $response ) || in_array( $status, self::RETRYABLE_STATUS_CODES, true ) );
			if ( ! $should_retry ) {
				break;
			}

			$delay_ms = $this->retry_delay_milliseconds( $response, $attempts );
			$this->logger->info(
				'Retrying temporary SooCool API error.',
				array(
					'method'   => $method,
					'status'   => $status,
					'attempt'  => $attempts,
					'delay_ms' => $delay_ms,
					'error'    => is_wp_error( $response ) ? $response->get_error_message() : '',
				)
			);
			if ( 0 < $delay_ms ) {
				usleep( $delay_ms * 1000 );
			}
		} while ( $attempts < self::MAX_RETRY_ATTEMPTS );

		return $response;
	}

	public function retry_delay_milliseconds( mixed $response, int $attempt ): int {
		$retry_after = is_wp_error( $response ) ? '' : trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		$seconds     = 0;
		if ( '' !== $retry_after && ctype_digit( $retry_after ) ) {
			$seconds = (int) $retry_after;
		} elseif ( '' !== $retry_after ) {
			$timestamp = strtotime( $retry_after );
			if ( false !== $timestamp ) {
				$seconds = max( 0, $timestamp - time() );
			}
		}

		$base_ms = 250 * ( 2 ** max( 0, $attempt - 1 ) );
		$jitter  = function_exists( 'wp_rand' ) ? wp_rand( 0, 250 ) : random_int( 0, 250 );
		$delay   = 0 < $seconds ? $seconds * 1000 : $base_ms + $jitter;
		return max( 0, min( 2000, (int) apply_filters( 'soocool_api_retry_delay_milliseconds', $delay, $attempt, $response ) ) );
	}

}
