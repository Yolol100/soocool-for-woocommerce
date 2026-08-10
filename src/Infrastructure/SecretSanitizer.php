<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class SecretSanitizer {

	private const MAX_DEPTH = 8;
	private const MAX_ITEMS = 100;

	/** @param array<string, mixed> $context @return array<string, mixed> */
	public function scrub( array $context ): array {
		return $this->scrub_value( $context );
	}

	private function scrub_value( mixed $value, string $parent_key = '', int $depth = 0 ): mixed {
		if ( $depth >= self::MAX_DEPTH ) {
			return '[truncated]';
		}

		if ( is_array( $value ) ) {
			$clean = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count >= self::MAX_ITEMS ) {
					break;
				}
				++$count;

				$key_string = is_string( $key ) ? $key : (string) $key;
				if ( $this->is_safe_debug_key( $key_string ) ) {
					$clean[ $key ] = $this->scrub_safe_debug_value( $item, $key_string, $depth );
					continue;
				}
				if ( $this->looks_secret( $key_string ) || $this->looks_personal_data( $key_string ) ) {
					$clean[ $key ] = '[redacted]';
					continue;
				}
				$clean[ $key ] = $this->scrub_value( $item, $key_string, $depth + 1 );
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			$value = $this->redact_string( $value, $parent_key );
			return sanitize_text_field( $value );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		return '[unsupported]';
	}

	private function scrub_safe_debug_value( mixed $value, string $key, int $depth ): mixed {
		if ( is_string( $value ) ) {
			$clean = sanitize_text_field( $value );
			if ( in_array( $key, array( 'orderId', 'traceId', 'trace_id', 'api_key_length' ), true ) && 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $clean ) ) {
				return $clean;
			}

			return sanitize_text_field( $this->redact_string( $value ) );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		return $this->scrub_value( $value, $key, $depth + 1 );
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

		$credential_key = '(?:api[_ -]?key|x-api-key|authorization|password|token|secret|(?:access|refresh|auth|bearer|webhook|client|consumer|private|session)[_-]?(?:token|secret|key|id)|secret[_-]?key)';
		$patterns = array(
			'/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i' => '[redacted-api-key]',
			'/([A-Z0-9._%+\-]+)@([A-Z0-9.\-]+\.[A-Z]{2,})/i' => '[redacted-email]',
			'/\b(?:\+?\d[\d\s().\-]{7,}\d)\b/' => '[redacted-phone]',
			'/\b\d{4}\s?[A-Z]{2}\b/i' => '[redacted-postcode]',
			'/\b(https?:\/\/)[^\/\s:@]+:[^@\s\/]+@/i' => '$1[redacted-credentials]@',
			'/(?<![A-Za-z0-9_])["\']?' . $credential_key . '["\']?(?![A-Za-z0-9_])\s*[:=]\s*(?:Bearer\s+)?(?:"[^"]*"|\'[^\']*\'|\[[^\]]*\]|[^\s,;]+)/i' => '[redacted-secret]',
			'/(?<![A-Za-z0-9_])["\']?' . $credential_key . '["\']?(?![A-Za-z0-9_])\s+(?:Bearer\s+)?(?:"[A-Za-z0-9_.:-]{16,128}"|\'[A-Za-z0-9_.:-]{16,128}\'|[A-Za-z0-9_.:-]{16,128})/i' => '[redacted-secret]',
			'/\bBearer\s+[^\s,;]+/i' => '[redacted-secret]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$value = preg_replace( $pattern, $replacement, $value ) ?? $value;
		}

		return $value;
	}

	private function looks_secret( string $key ): bool {
		$normalized = $this->normalized_key( $key );
		if ( '' === $normalized ) {
			return false;
		}

		if ( in_array( $normalized, array( 'authorization', 'password', 'passwd', 'bearer', 'secret', 'token', 'api_key', 'x_api_key', 'secret_key' ), true ) ) {
			return true;
		}

		$compact = str_replace( '_', '', $normalized );
		if ( in_array(
			$compact,
			array(
				'apikey',
				'xapikey',
				'accesskey',
				'accesstoken',
				'refreshkey',
				'refreshtoken',
				'authkey',
				'authtoken',
				'bearertoken',
				'webhookkey',
				'webhooktoken',
				'webhooksecret',
				'clientkey',
				'clienttoken',
				'clientsecret',
				'clientpassword',
				'clientid',
				'consumerkey',
				'consumertoken',
				'consumersecret',
				'consumerpassword',
				'consumerid',
				'privatekey',
				'privatetoken',
				'privatesecret',
				'sessionkey',
				'sessiontoken',
				'sessionsecret',
				'secretkey',
			),
			true
		) ) {
			return true;
		}

		return 1 === preg_match( '/(?:^|_)(?:api|access|refresh|auth|bearer|webhook|client|consumer|private|session)_(?:key|token|secret|password|id)(?:_|$)/', $normalized );
	}

	private function looks_personal_data( string $key ): bool {
		$normalized = $this->normalized_key( $key );
		if ( '' === $normalized ) {
			return false;
		}

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
			if ( $normalized === $personal_key || str_starts_with( $normalized, $personal_key . '_' ) || str_ends_with( $normalized, '_' . $personal_key ) || str_contains( $normalized, '_' . $personal_key . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalized_key( string $key ): string {
		$key = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', trim( $key ) ) ?? trim( $key );
		$key = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '_', $key ) ?? $key );

		return trim( $key, '_' );
	}

}
