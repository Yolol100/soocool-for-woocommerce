<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApiClient {

	private const RETRYABLE_STATUS_CODES = array( 502, 503 );
	private const REQUEST_TIMEOUT_SECONDS = 10;
	private const MAX_RETRY_ATTEMPTS = 2;

	public function __construct( private readonly OptionRepository $options, private readonly Logger $logger ) {}

	public function ping(): ApiResponse {
		return $this->request( 'GET', '/ping' );
	}

	/** @param array<string, mixed> $payload */
	public function create_order( array $payload ): ApiResponse {
		return $this->request( 'POST', '/order', $payload );
	}

	public function search_order_by_reference( string $order_reference ): ApiResponse {
		$order_reference = trim( sanitize_text_field( $order_reference ) );
		if ( '' === $order_reference ) {
			throw new ApiException( esc_html__( 'Missing SooCool order reference.', 'soocool-for-woocommerce' ), 0 );
		}

		return $this->request( 'GET', '/order?orderReference=' . rawurlencode( $order_reference ) );
	}

	public function get_order( int|string $order_id ): ApiResponse {
		return $this->request( 'GET', '/order/' . $this->encode_numeric_order_id( $order_id ) );
	}

	/** @param array<string, mixed> $payload */
	public function update_order( int|string $order_id, array $payload ): ApiResponse {
		return $this->request( 'PUT', '/order/' . $this->encode_numeric_order_id( $order_id ), $payload );
	}

	public function cancel_order( int|string $order_id ): ApiResponse {
		return $this->request( 'DELETE', '/order/' . $this->encode_numeric_order_id( $order_id ) );
	}

	public function get_shipping_label( int|string $order_id, string $output = 'a6' ): ApiResponse {
		$output = $this->normalize_label_output( $output );
		return $this->request( 'GET', '/order/' . $this->encode_numeric_order_id( $order_id ) . '/shipping-label?output=' . rawurlencode( $output ), null, array( 'Accept' => 'application/pdf' ) );
	}

	public function get_good_shipping_label( int|string $order_id, int|string $good_id, string $output = 'a6' ): ApiResponse {
		$output = $this->normalize_label_output( $output );
		return $this->request( 'GET', '/order/' . $this->encode_numeric_order_id( $order_id ) . '/good/' . $this->encode_numeric_order_id( $good_id ) . '/shipping-label?output=' . rawurlencode( $output ), null, array( 'Accept' => 'application/pdf' ) );
	}

	/** @param array<int, int|string> $order_ids */
	public function get_multiple_shipping_labels( array $order_ids, string $output = 'a6' ): ApiResponse {
		$ids = array_values( array_unique( array_map( array( $this, 'encode_numeric_order_id' ), $order_ids ) ) );
		if ( array() === $ids ) {
			throw new ApiException( esc_html__( 'Missing valid SooCool order IDs for label download.', 'soocool-for-woocommerce' ), 0 );
		}

		$output = $this->normalize_label_output( $output );
		return $this->request( 'GET', '/shipping-label?orderIds=' . rawurlencode( implode( ',', $ids ) ) . '&output=' . rawurlencode( $output ), null, array( 'Accept' => 'application/pdf' ) );
	}

	private function normalize_label_output( string $output ): string {
		return 'collated_a4' === $output ? 'collated_a4' : 'a6';
	}

	private function encode_numeric_order_id( int|string $order_id ): string {
		$normalized = trim( sanitize_text_field( (string) $order_id ) );
		if ( ! ctype_digit( $normalized ) || 0 >= (int) $normalized ) {
			throw new ApiException( esc_html__( 'Missing valid numeric SooCool order ID.', 'soocool-for-woocommerce' ), 0 );
		}

		return (string) (int) $normalized;
	}

	/** @param array<string, string> $extra_headers */
	private function request( string $method, string $path, ?array $payload = null, array $extra_headers = array() ): ApiResponse {
		$api_key = trim( $this->options->api_key() );
		$url     = $this->options->base_url() . $path;
		if ( '' === $api_key ) {
			$this->logger->error(
				'SooCool API key is missing or invalid before request.',
				$this->api_key_debug_context( $api_key, $url, $path )
			);
			throw new ApiException( esc_html__( 'Missing or invalid SooCool API key. Paste and save the API key again.', 'soocool-for-woocommerce' ), 401 );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::REQUEST_TIMEOUT_SECONDS,
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'X-API-Key'    => $api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
					'User-Agent'   => 'SooCool for WooCommerce/' . SOOCOOL_VERSION . '; ' . home_url( '/' ),
				),
				$extra_headers
			),
		);

		if ( null !== $payload ) {
			$json = wp_json_encode( $payload );
			if ( false === $json ) {
				throw new ApiException( esc_html__( 'Could not encode SooCool payload.', 'soocool-for-woocommerce' ), 0 );
			}
			$args['body'] = $json;
		}

		$response = $this->remote_request_with_retry( $url, $args );
		if ( is_wp_error( $response ) ) {
			$this->logger->error(
				'SooCool request failed.',
				array(
					'method' => $method,
					'path'   => $path,
					'error'  => $response->get_error_message(),
				)
			);
			throw new ApiException( esc_html__( 'Could not connect to the SooCool API. Please try again or check the SooCool logs.', 'soocool-for-woocommerce' ), 0 );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$raw          = (string) wp_remote_retrieve_body( $response );
		$headers      = wp_remote_retrieve_headers( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body         = str_contains( $content_type, 'application/pdf' ) ? $raw : $this->decode_body( $raw );

		if ( $status < 200 || $status >= 300 ) {
			$errors  = $this->extract_errors( $body );
			$trace_id = $this->extract_trace_id( $body );
			$message = $this->public_error_message( $status );
			$context = array_merge(
				array(
					'method' => $method,
					'path'   => $path,
					'status' => $status,
					'errors' => $errors,
				),
				$this->api_key_debug_context( $api_key, $url, $path )
			);
			if ( '' !== $trace_id ) {
				$context['traceId'] = $trace_id;
			}
			$this->logger->error( 'SooCool API error.', $context );
			throw new ApiException( esc_html( $message ), absint( $status ), array_map( 'esc_html', $errors ) );
		}

		$this->logger->info(
			'SooCool API request completed.',
			array(
				'method' => $method,
				'path'   => $path,
				'status' => $status,
			)
		);
		return new ApiResponse( $status, $body, method_exists( $headers, 'getAll' ) ? $headers->getAll() : array() );
	}


	/** @return array<string, string|int|bool> */
	private function api_key_debug_context( string $api_key, string $url, string $path ): array {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = is_string( $host ) ? $host : '';

		return array(
			'api_key_present' => '' !== $api_key,
			'api_key_source'  => $this->options->api_key_source(),
			'api_key_status'  => $this->options->api_key_status(),
			'api_key_length'  => strlen( $api_key ),
			'api_key_first4'  => strlen( $api_key ) >= 8 ? substr( $api_key, 0, 4 ) : '',
			'api_key_last4'   => strlen( $api_key ) >= 8 ? substr( $api_key, -4 ) : '',
			'header_name_sent' => 'X-API-Key',
			'request_url_host' => $host,
			'request_path'     => $path,
		);
	}

	private function decode_body( string $raw ): mixed {
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : $raw;
	}

	private function public_error_message( int $status ): string {
		return match ( $status ) {
			400, 422 => esc_html__( 'SooCool rejected the request. Check the order data and SooCool logs.', 'soocool-for-woocommerce' ),
			401, 403 => esc_html__( 'SooCool authentication failed. Check the configured API key.', 'soocool-for-woocommerce' ),
			404 => esc_html__( 'The requested SooCool resource could not be found.', 'soocool-for-woocommerce' ),
			412 => esc_html__( 'SooCool could not generate the label because a precondition failed. Check the SooCool logs and order data.', 'soocool-for-woocommerce' ),
			429 => esc_html__( 'SooCool rate limit reached. Please try again later.', 'soocool-for-woocommerce' ),
			500, 502, 503, 504 => esc_html__( 'SooCool is temporarily unavailable. Please try again later.', 'soocool-for-woocommerce' ),
			default => sprintf(
				/* translators: %d: HTTP status code. */
				esc_html__( 'SooCool API returned HTTP %d. Check the SooCool logs for details.', 'soocool-for-woocommerce' ),
				$status
			),
		};
	}

	/** @return array<int, string> */
	private function extract_errors( mixed $body ): array {
		if ( ! is_array( $body ) ) {
			return array();
		}

		if ( array_key_exists( 'errors', $body ) ) {
			return $this->flatten_error_values( $body['errors'] );
		}

		if ( isset( $body['message'] ) ) {
			return $this->flatten_error_values( $body['message'] );
		}

		return array();
	}

	/** @return array<int, string> */
	private function flatten_error_values( mixed $value ): array {
		if ( is_scalar( $value ) ) {
			$error = trim( sanitize_text_field( (string) $value ) );
			return '' !== $error ? array( $error ) : array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$errors = array();
		foreach ( $value as $item ) {
			foreach ( $this->flatten_error_values( $item ) as $error ) {
				$errors[] = $error;
			}
		}

		return array_values( array_unique( $errors ) );
	}

	private function extract_trace_id( mixed $body ): string {
		if ( ! is_array( $body ) || ! isset( $body['traceId'] ) || ! is_scalar( $body['traceId'] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $body['traceId'] );
	}

	/** @param array<string, mixed> $args @return array<string, mixed>|WP_Error */
	private function remote_request_with_retry( string $url, array $args ): array|WP_Error {
		$attempts = 0;
		$response = null;

		do {
			++$attempts;
			$response = wp_remote_request( $url, $args );
			$status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( ! in_array( $status, self::RETRYABLE_STATUS_CODES, true ) ) {
				break;
			}
			$this->logger->info(
				'Retrying temporary SooCool API error.',
				array(
					'status'  => $status,
					'attempt' => $attempts,
				)
			);
		} while ( $attempts < self::MAX_RETRY_ATTEMPTS );

		return $response;
	}
}
