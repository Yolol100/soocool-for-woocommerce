<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class ApiCredentialResolver {

	public const MASK_PLACEHOLDER = '__SOOCOOL_KEEP_CURRENT_SECRET__';

	private const DEFAULT_ALLOWED_API_HOSTS = array( 'api.staging.soocool.nl', 'api.soocool.nl' );

	public function sanitize_webhook_secret( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( sanitize_text_field( (string) $value ) );
		return 1 === preg_match( '/^[A-Za-z0-9]{32,128}$/', $value ) ? $value : '';
	}

	public function generate_webhook_secret(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 48, false, false );
		}

		return bin2hex( random_bytes( 24 ) );
	}

	public function sanitize_secret( mixed $value, string $current ): string {
		$current = $this->normalize_secret( $current );
		if ( null === $value ) {
			return $current;
		}

		if ( ! is_scalar( $value ) ) {
			return $current;
		}

		$raw = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $raw || self::MASK_PLACEHOLDER === $raw || $this->is_masked_or_invalid_secret( $raw ) ) {
			return $current;
		}

		$secret = $this->normalize_secret( $raw );
		return '' !== $secret ? $secret : $current;
	}

	public function normalized_constant_api_key(): string {
		if ( ! defined( 'SOOCOOL_API_KEY' ) ) {
			return '';
		}

		$constant_api_key = constant( 'SOOCOOL_API_KEY' );
		return is_string( $constant_api_key ) ? $this->normalize_secret( $constant_api_key ) : '';
	}

	public function normalize_secret( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || $this->is_masked_or_invalid_secret( $value ) ) {
			return '';
		}

		if ( preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $matches ) ) {
			return strtolower( $matches[0] );
		}

		$value = preg_replace( '/^(?:api\s*key|x-api-key)\s*[:=]\s*/i', '', $value ) ?? $value;
		$value = preg_replace( '/\s+/', '', trim( $value ) ) ?? trim( $value );
		return $this->looks_like_api_key( $value ) ? $value : '';
	}

	public function is_masked_or_invalid_secret( string $value ): bool {
		$value = trim( $value );
		return str_contains( $value, '***' ) || str_contains( $value, '•' ) || str_contains( $value, '[redacted]' ) || str_contains( $value, self::MASK_PLACEHOLDER );
	}

	public function looks_like_api_key( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_.:-]{16,128}$/', $value );
	}

	public function sanitize_url( string $value, string $fallback ): string {
		$url = esc_url_raw( $value );
		if ( ! $this->is_allowed_api_url( $url ) ) {
			return $fallback;
		}

		return untrailingslashit( $url );
	}

	public function sanitize_url_or_empty( string $value ): string {
		$url = esc_url_raw( $value );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		return false !== wp_http_validate_url( $url ) ? $url : '';
	}

	public function is_allowed_api_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
			return false;
		}

		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] ) ) {
			return false;
		}

		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'] ) : '';
		if ( '' !== $path && '/' !== $path ) {
			return false;
		}

		if ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) {
			return false;
		}

		$host          = strtolower( (string) $parts['host'] );
		$allowed_hosts = apply_filters( 'soocool_allowed_api_hosts', self::DEFAULT_ALLOWED_API_HOSTS );
		if ( ! is_array( $allowed_hosts ) ) {
			$allowed_hosts = self::DEFAULT_ALLOWED_API_HOSTS;
		}

		$normalized_hosts = array();
		foreach ( $allowed_hosts as $allowed_host ) {
			if ( ! is_scalar( $allowed_host ) ) {
				continue;
			}

			$allowed_host = strtolower( trim( (string) $allowed_host ) );
			if ( '' !== $allowed_host ) {
				$normalized_hosts[] = $allowed_host;
			}
		}

		return in_array( $host, array_values( array_unique( $normalized_hosts ) ), true );
	}

}
