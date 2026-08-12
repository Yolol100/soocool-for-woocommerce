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
	public const MAX_INLINE_RETRY_DELAY_MILLISECONDS = 2000;
	private const MAX_RETRY_AFTER_SECONDS = 86400;
	private const COOLDOWN_TRANSIENT_PREFIX = 'soocool_api_cooldown_';
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

		$cooldown_remaining = $this->cooldown_remaining_seconds( $api_key );
		if ( 0 < $cooldown_remaining ) {
			throw new ApiException(
				__( 'De SooCool API heeft tijdelijk een snelheidslimiet ingesteld. De actie wordt pas na de opgegeven wachttijd opnieuw geprobeerd.', 'soocool-for-woocommerce' ),
				429,
				array(),
				true,
				$cooldown_remaining
			);
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
			$retry_after_seconds = $this->retry_after_seconds( $response );
			if ( in_array( $status, self::RETRYABLE_STATUS_CODES, true ) && 0 < $retry_after_seconds ) {
				$this->store_cooldown( $api_key, $retry_after_seconds );
			}

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
			throw new ApiException( $message, absint( $status ), $errors, null, $retry_after_seconds );
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
			if ( $delay_ms > self::MAX_INLINE_RETRY_DELAY_MILLISECONDS ) {
				$this->logger->info(
					'Deferring SooCool API retry because the provider requested a longer Retry-After delay.',
					array(
						'method'   => $method,
						'status'   => $status,
						'attempt'  => $attempts,
						'delay_ms' => $delay_ms,
					)
				);
				break;
			}

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
		$retry_after_seconds = $this->retry_after_seconds( $response );
		$base_ms             = 250 * ( 2 ** max( 0, $attempt - 1 ) );
		$jitter              = function_exists( 'wp_rand' ) ? wp_rand( 0, 250 ) : random_int( 0, 250 );
		$delay               = 0 < $retry_after_seconds ? $retry_after_seconds * 1000 : $base_ms + $jitter;
		$delay               = (int) apply_filters( 'soocool_api_retry_delay_milliseconds', $delay, $attempt, $response );

		return max( 0, min( self::MAX_RETRY_AFTER_SECONDS * 1000, $delay ) );
	}

	public function retry_after_seconds( mixed $response ): int {
		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$retry_after = trim( (string) wp_remote_retrieve_header( $response, 'retry-after' ) );
		if ( '' === $retry_after ) {
			return 0;
		}

		if ( ctype_digit( $retry_after ) ) {
			$seconds = (int) $retry_after;
		} else {
			$timestamp = strtotime( $retry_after );
			$seconds   = false === $timestamp ? 0 : max( 0, $timestamp - time() );
		}

		return max( 0, min( self::MAX_RETRY_AFTER_SECONDS, $seconds ) );
	}

	public function cooldown_remaining_seconds( string $api_key ): int {
		if ( ! function_exists( 'get_transient' ) ) {
			return 0;
		}

		$until = get_transient( $this->cooldown_key( $api_key ) );
		if ( ! is_numeric( $until ) ) {
			return 0;
		}

		$remaining = (int) $until - time();
		if ( 0 >= $remaining ) {
			if ( function_exists( 'delete_transient' ) ) {
				delete_transient( $this->cooldown_key( $api_key ) );
			}
			return 0;
		}

		return min( self::MAX_RETRY_AFTER_SECONDS, $remaining );
	}

	public function store_cooldown( string $api_key, int $seconds ): void {
		$seconds = max( 0, min( self::MAX_RETRY_AFTER_SECONDS, $seconds ) );
		if ( 0 === $seconds || ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( $this->cooldown_key( $api_key ), time() + $seconds, $seconds );
	}

	private function cooldown_key( string $api_key ): string {
		$context = untrailingslashit( $this->options->base_url() ) . "\0" . trim( $api_key );
		$digest  = hash_hmac( 'sha256', $context, wp_salt( 'auth' ) );

		return self::COOLDOWN_TRANSIENT_PREFIX . substr( $digest, 0, 32 );
	}

}
