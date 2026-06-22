<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Api;

defined( 'ABSPATH' ) || exit;

final class ApiErrorMapper {

	public function public_message( int $status ): string {
		return match ( $status ) {
			400, 422 => esc_html__( 'SooCool heeft de aanvraag geweigerd. Controleer de ordergegevens en SooCool-logs.', 'soocool-for-woocommerce' ),
			401, 403 => esc_html__( 'SooCool-authenticatie mislukt. Controleer de ingestelde API-key.', 'soocool-for-woocommerce' ),
			404 => esc_html__( 'De gevraagde SooCool-resource is niet gevonden.', 'soocool-for-woocommerce' ),
			412 => esc_html__( 'SooCool kon het label niet genereren omdat niet aan een voorwaarde is voldaan. Controleer de SooCool-logs en ordergegevens.', 'soocool-for-woocommerce' ),
			429 => esc_html__( 'SooCool-rate limit bereikt. Probeer het later opnieuw.', 'soocool-for-woocommerce' ),
			500, 502, 503, 504 => esc_html__( 'SooCool is tijdelijk niet beschikbaar. Probeer het later opnieuw.', 'soocool-for-woocommerce' ),
			default => sprintf(
				/* translators: %d: HTTP status code. */
				esc_html__( 'SooCool API gaf HTTP %d terug. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ),
				$status
			),
		};
	}

	/** @return array<int, string> */
	public function redacted_errors( mixed $body ): array {
		return $this->redact_error_list( $this->extract_errors( $body ) );
	}

	public function trace_id( mixed $body ): string {
		if ( ! is_array( $body ) || ! isset( $body['traceId'] ) || ! is_scalar( $body['traceId'] ) ) {
			return '';
		}

		return sanitize_text_field( (string) $body['traceId'] );
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
		$patterns = array(
			'/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i' => '[redacted-api-key]',
			'/([A-Z0-9._%+\-]+)@([A-Z0-9.\-]+\.[A-Z]{2,})/i' => '[redacted-email]',
			'/\b(?:\+?\d[\d\s().\-]{7,}\d)\b/' => '[redacted-phone]',
			'/\b\d{4}\s?[A-Z]{2}\b/i' => '[redacted-postcode]',
			'/\b(?:api[_ -]?key|x-api-key|authorization|token|secret|password)\s*[:=]\s*(?:Bearer\s+)?[^\s,;]+/i' => '[redacted-secret]',
			'/\bBearer\s+[^\s,;]+/i' => '[redacted-secret]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$value = preg_replace( $pattern, $replacement, $value ) ?? $value;
		}

		return trim( sanitize_text_field( $value ) );
	}
}
