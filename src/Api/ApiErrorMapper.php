<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

use SooCool\WooCommerce\Infrastructure\SecretSanitizer;

defined( 'ABSPATH' ) || exit;

final class ApiErrorMapper {

	private const MAX_ERROR_DEPTH = 8;
	private const MAX_ERRORS      = 25;
	private const MAX_TRACE_DEPTH = 5;
	private const MAX_TRACE_NODES = 100;

	private readonly SecretSanitizer $sanitizer;

	public function __construct( ?SecretSanitizer $sanitizer = null ) {
		$this->sanitizer = $sanitizer ?? new SecretSanitizer();
	}

	public function public_message( int $status ): string {
		return match ( $status ) {
			400, 422 => __( 'SooCool heeft de aanvraag geweigerd. Controleer de ordergegevens en SooCool-logs.', 'soocool-for-woocommerce' ),
			401, 403 => __( 'SooCool-authenticatie mislukt. Controleer de ingestelde API-key.', 'soocool-for-woocommerce' ),
			404 => __( 'De gevraagde SooCool-resource is niet gevonden.', 'soocool-for-woocommerce' ),
			412 => __( 'SooCool kon het label niet genereren omdat niet aan een voorwaarde is voldaan. Controleer de SooCool-logs en ordergegevens.', 'soocool-for-woocommerce' ),
			429 => __( 'SooCool-rate limit bereikt. Probeer het later opnieuw.', 'soocool-for-woocommerce' ),
			500, 502, 503, 504 => __( 'SooCool is tijdelijk niet beschikbaar. Probeer het later opnieuw.', 'soocool-for-woocommerce' ),
			default => sprintf(
				/* translators: %d: HTTP status code. */
				__( 'SooCool API gaf HTTP %d terug. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ),
				$status
			),
		};
	}

	/** @return array<int, string> */
	public function redacted_errors( mixed $body ): array {
		return $this->redact_error_list( $this->extract_errors( $body ) );
	}

	public function trace_id( mixed $body ): string {
		if ( ! is_array( $body ) ) {
			return '';
		}

		$nodes = 0;
		return $this->trace_id_from_array( $body, 0, $nodes );
	}

	/** @param array<mixed> $value */
	private function trace_id_from_array( array $value, int $depth, int &$nodes ): string {
		if ( $depth > self::MAX_TRACE_DEPTH || $nodes >= self::MAX_TRACE_NODES ) {
			return '';
		}
		++$nodes;

		foreach ( array( 'traceId', 'trace_id' ) as $key ) {
			if ( ! isset( $value[ $key ] ) || ! is_scalar( $value[ $key ] ) ) {
				continue;
			}

			$trace_id = trim( sanitize_text_field( (string) $value[ $key ] ) );
			if ( 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $trace_id ) ) {
				return $trace_id;
			}
		}

		foreach ( $value as $nested ) {
			if ( ! is_array( $nested ) ) {
				continue;
			}
			$trace_id = $this->trace_id_from_array( $nested, $depth + 1, $nodes );
			if ( '' !== $trace_id ) {
				return $trace_id;
			}
		}

		return '';
	}

	/** @return array<int, string> */
	private function extract_errors( mixed $body ): array {
		if ( ! is_array( $body ) ) {
			return array();
		}

		if ( array_key_exists( 'errors', $body ) ) {
			$count  = 0;
			$errors = $this->flatten_error_values( $body['errors'], 0, $count, 'errors' );
			if ( array() !== $errors ) {
				return $errors;
			}
		}

		if ( isset( $body['message'] ) ) {
			$count = 0;
			return $this->flatten_error_values( $body['message'], 0, $count, 'message' );
		}

		return array();
	}

	/** @return array<int, string> */
	private function flatten_error_values( mixed $value, int $depth = 0, int &$count = 0, string $parent_key = '' ): array {
		if ( $depth > self::MAX_ERROR_DEPTH || $count >= self::MAX_ERRORS ) {
			return array();
		}

		if ( is_scalar( $value ) ) {
			$error = $this->sanitizer->scrub_text( (string) $value, $parent_key );
			if ( '' === $error ) {
				return array();
			}

			++$count;
			return array( $error );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$errors = array();
		foreach ( $value as $key => $item ) {
			if ( $count >= self::MAX_ERRORS ) {
				break;
			}
			$child_key = is_string( $key ) ? $key : $parent_key;
			foreach ( $this->flatten_error_values( $item, $depth + 1, $count, $child_key ) as $error ) {
				$errors[] = $error;
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/** @param array<int, string> $errors @return array<int, string> */
	private function redact_error_list( array $errors ): array {
		$redacted = array();
		foreach ( $errors as $error ) {
			$error = $this->redact_error_string( $error );
			if ( '' !== $error ) {
				$redacted[] = $error;
			}
		}

		return array_values( array_unique( $redacted ) );
	}

	private function redact_error_string( string $value ): string {
		return $this->sanitizer->scrub_text( $value );
	}
}
