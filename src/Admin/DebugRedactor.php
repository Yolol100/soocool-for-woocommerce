<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class DebugRedactor {

	public function __construct( private readonly OptionRepository $options ) {}

	public function redact( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$key_string = is_scalar( $key ) ? (string) $key : '';
				if ( $this->is_sensitive_key( $key_string ) ) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}
				$redacted[ $key ] = $this->redact( $item );
			}

			return $redacted;
		}

		return is_string( $value ) ? $this->redact_string( $value ) : $value;
	}

	/** @param array<int, string> $errors @return array<int, string> */
	public function redact_error_list( array $errors ): array {
		$redacted = array();
		foreach ( $errors as $error ) {
			if ( is_scalar( $error ) ) {
				$value = sanitize_text_field( (string) $error );
				if ( '' !== $value ) {
					$redacted[] = $this->redact_string( $value );
				}
			}
		}

		return $redacted;
	}

	private function is_sensitive_key( string $key ): bool {
		$key = strtolower( $key );

		return in_array(
			$key,
			array(
				'email', 'phone', 'mobile', 'person', 'firstname', 'first_name', 'lastname', 'last_name', 'name',
				'contactname', 'contact_name', 'street', 'housenumber', 'house_number', 'address', 'postcode',
				'post_code', 'postalcode', 'postal_code', 'city', 'company', 'api_key', 'apikey', 'token', 'authorization',
			),
			true
		);
	}

	private function redact_string( string $value ): string {
		$value = sanitize_text_field( $value );

		$api_key = $this->options->api_key();
		if ( '' !== $api_key ) {
			$value = str_replace( $api_key, '[redacted]', $value );
		}

		$secret = $this->options->existing_webhook_secret();
		if ( '' !== $secret ) {
			$value = str_replace( $secret, '[redacted]', $value );
		}

		$value = preg_replace( '/([?&]token=)[^&\s]+/i', '$1[redacted]', $value ) ?? $value;
		$value = preg_replace( '/(x-api-key\s*[:=]\s*)[^\s,;]+/i', '$1[redacted]', $value ) ?? $value;

		return $value;
	}
}
