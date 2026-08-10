<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class HttpsUrl {

	public static function sanitize( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$raw = trim( (string) $value );
		if ( '' === $raw || 1 === preg_match( '/[\x00-\x20\x7F]/', $raw ) ) {
			return '';
		}

		$url = esc_url_raw( $raw );
		if ( '' === $url || false === wp_http_validate_url( $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		return $url;
	}
}
