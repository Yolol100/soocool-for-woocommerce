<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SecretSanitizer {

	/** @param array<string, mixed> $context @return array<string, mixed> */
	public function scrub( array $context ): array {
		return $this->scrub_value( $context );
	}

	private function scrub_value( mixed $value, string $parent_key = '' ): mixed {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$key_string = is_string( $key ) ? $key : (string) $key;
				if ( $this->is_safe_debug_key( $key_string ) ) {
					$clean[ $key ] = is_scalar( $item ) ? sanitize_text_field( (string) $item ) : $item;
					continue;
				}
				if ( $this->looks_secret( $key_string ) || $this->looks_personal_data( $key_string ) ) {
					$clean[ $key ] = '[redacted]';
					continue;
				}
				$clean[ $key ] = $this->scrub_value( $item, $key_string );
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			$value = $this->redact_string( $value, $parent_key );
			return sanitize_text_field( $value );
		}

		return $value;
	}

	private function is_safe_debug_key( string $key ): bool {
		return in_array(
			$key,
			array(
				'api_key_present',
				'api_key_source',
				'api_key_length',
				'api_key_status',
				'traceId',
				'trace_id',
				'orderId',
				'header_name_sent',
				'request_url_host',
				'request_path',
			),
			true
		);
	}

	public function scrub_text( string $value, string $parent_key = '' ): string {
		return trim( sanitize_text_field( $this->redact_string( $value, $parent_key ) ) );
	}

	private function redact_string( string $value, string $parent_key = '' ): string {
		if ( $this->looks_secret( $parent_key ) || $this->looks_personal_data( $parent_key ) ) {
			return '[redacted]';
		}

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

		return $value;
	}

	private function looks_secret( string $key ): bool {
		$key = strtolower( $key );
		return str_contains( $key, 'key' ) || str_contains( $key, 'token' ) || str_contains( $key, 'secret' ) || str_contains( $key, 'password' ) || str_contains( $key, 'authorization' );
	}

	private function looks_personal_data( string $key ): bool {
		$key = strtolower( $key );
		$personal_keys = array(
			'address',
			'billing',
			'city',
			'contact',
			'customer',
			'email',
			'firstname',
			'first_name',
			'house',
			'lastname',
			'last_name',
			'name',
			'phone',
			'postcode',
			'postal',
			'recipient',
			'shipping',
			'street',
			'zip',
		);

		foreach ( $personal_keys as $personal_key ) {
			if ( str_contains( $key, $personal_key ) ) {
				return true;
			}
		}

		return false;
	}
}
