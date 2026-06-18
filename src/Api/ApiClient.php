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

	private const RETRYABLE_STATUS_CODES = array( 502, 503, 504 );
	private const REQUEST_TIMEOUT_SECONDS = 10;
	private const MAX_RETRY_ATTEMPTS = 2;

	public function __construct( private readonly OptionRepository $options, private readonly Logger $logger, private readonly ApiErrorMapper $errors ) {}

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
			throw new ApiException( esc_html__( 'SooCool orderreferentie ontbreekt.', 'soocool-for-woocommerce' ), 0 );
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
			throw new ApiException( esc_html__( 'Geldige SooCool order-ID’s voor labeldownload ontbreken.', 'soocool-for-woocommerce' ), 0 );
		}

		$output = $this->normalize_label_output( $output );
		return $this->request( 'GET', '/shipping-label?orderIds=' . implode( ',', array_map( 'rawurlencode', $ids ) ) . '&output=' . rawurlencode( $output ), null, array( 'Accept' => 'application/pdf' ) );
	}

	/** @param array<int, int|string> $good_ids */
	public function get_multiple_good_shipping_labels( array $good_ids, string $output = 'a6' ): ApiResponse {
		$ids = array_values( array_unique( array_map( array( $this, 'encode_numeric_order_id' ), $good_ids ) ) );
		if ( array() === $ids ) {
			throw new ApiException( esc_html__( 'Geldige SooCool-goederen-ID’s voor labeldownload ontbreken.', 'soocool-for-woocommerce' ), 0 );
		}

		$output = $this->normalize_label_output( $output );
		return $this->request( 'GET', '/shipping-label?goodIds=' . implode( ',', array_map( 'rawurlencode', $ids ) ) . '&output=' . rawurlencode( $output ), null, array( 'Accept' => 'application/pdf' ) );
	}

	private function normalize_label_output( string $output ): string {
		return 'collated_a4' === $output ? 'collated_a4' : 'a6';
	}

	private function encode_numeric_order_id( int|string $order_id ): string {
		$normalized = trim( sanitize_text_field( (string) $order_id ) );
		if ( ! ctype_digit( $normalized ) || 0 >= (int) $normalized ) {
			throw new ApiException( esc_html__( 'Geldige numerieke SooCool order-ID ontbreekt.', 'soocool-for-woocommerce' ), 0 );
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
			throw new ApiException( esc_html__( 'SooCool API-key ontbreekt of is ongeldig. Plak en bewaar de API-key opnieuw.', 'soocool-for-woocommerce' ), 401 );
		}

		$headers = array_merge(
			array(
				'X-API-Key'  => $api_key,
				'Accept'     => 'application/json',
				'User-Agent' => 'SooCool for WooCommerce/' . SOOCOOL_VERSION . '; ' . home_url( '/' ),
			),
			$extra_headers
		);

		$args = array(
			'method'      => $method,
			'timeout'     => self::REQUEST_TIMEOUT_SECONDS,
			'redirection' => 0,
			'headers'     => $headers,
		);

		if ( null !== $payload ) {
			$json = wp_json_encode( $payload );
			if ( false === $json ) {
				throw new ApiException( esc_html__( 'Kon de SooCool payload niet coderen.', 'soocool-for-woocommerce' ), 0 );
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
			throw new ApiException( esc_html__( 'Kon geen verbinding maken met de SooCool API. Probeer opnieuw of controleer de SooCool logs.', 'soocool-for-woocommerce' ), 0 );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$raw          = (string) wp_remote_retrieve_body( $response );
		$headers      = wp_remote_retrieve_headers( $response );
		$content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$body         = str_contains( $content_type, 'application/pdf' ) ? $raw : $this->decode_body( $raw );

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
			throw new ApiException( esc_html( $message ), absint( $status ), array_map( 'esc_html', $errors ) );
		}

		$this->logger->info(
			'SooCool API request completed.',
			array(
				'method' => $method,
				'path'   => $this->log_path( $path ),
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

	private function decode_body( string $raw ): mixed {
		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : $raw;
	}

	/** @param array<string, mixed> $args @return array<string, mixed>|\WP_Error */
	private function remote_request_with_retry( string $method, string $url, array $args ): array|\WP_Error {
		$attempts = 0;
		$response = null;

		$method    = strtoupper( $method );
		$may_retry = in_array( $method, array( 'GET', 'HEAD' ), true );

		do {
			++$attempts;
			$response = wp_remote_request( $url, $args );
			$status       = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$should_retry = $may_retry && $attempts < self::MAX_RETRY_ATTEMPTS && ( is_wp_error( $response ) || in_array( $status, self::RETRYABLE_STATUS_CODES, true ) );
			if ( ! $should_retry ) {
				break;
			}
			$this->logger->info(
				'Retrying temporary SooCool API error.',
				array(
					'method'  => $method,
					'status'  => $status,
					'attempt' => $attempts,
					'error'   => is_wp_error( $response ) ? $response->get_error_message() : '',
				)
			);
		} while ( $attempts < self::MAX_RETRY_ATTEMPTS );

		return $response;
	}
}
