<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class WebhookIdentifiers {

	public function request_identifiers_are_consistent( WP_REST_Request $request ): bool {
		$route_params = $request->get_url_params();
		$query_params = $request->get_query_params();
		$route_params = is_array( $route_params ) ? $route_params : array();
		$query_params = is_array( $query_params ) ? $query_params : array();

		$wc_order_ids = array();
		if ( array_key_exists( 'wc_order_id', $route_params ) ) {
			$wc_order_ids[] = $route_params['wc_order_id'];
		}
		foreach ( array( 'wc_order_id', 'woo_order_id', 'woocommerce_order_id' ) as $key ) {
			if ( array_key_exists( $key, $query_params ) ) {
				$wc_order_ids[] = $query_params[ $key ];
			}
		}

		$order_references = array();
		foreach ( array( 'order_reference', 'orderReference', 'soocool_order_reference', 'reference' ) as $key ) {
			if ( array_key_exists( $key, $query_params ) ) {
				$order_references[] = $query_params[ $key ];
			}
		}

		return $this->request_positive_identifiers_are_consistent( $wc_order_ids )
			&& $this->request_text_identifiers_are_consistent( $order_references );
	}

	private function request_positive_identifiers_are_consistent( array $values ): bool {
		return $this->normalized_identifiers_are_consistent(
			$values,
			static function ( mixed $value ): ?string {
				$id = NumericIdentifier::positive( trim( (string) $value ) );
				return null === $id ? null : (string) $id;
			}
		);
	}

	private function request_text_identifiers_are_consistent( array $values ): bool {
		return $this->normalized_identifiers_are_consistent(
			$values,
			static function ( mixed $value ): ?string {
				$reference = trim( sanitize_text_field( (string) $value ) );
				return '' === $reference ? null : $reference;
			}
		);
	}

	/** @param array<int, mixed> $values @param callable(mixed): ?string $normalizer */
	private function normalized_identifiers_are_consistent( array $values, callable $normalizer ): bool {
		$normalized = array();
		foreach ( $values as $value ) {
			if ( null === $value || ( is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				return false;
			}

			$identifier = $normalizer( $value );
			if ( null === $identifier ) {
				return false;
			}
			$normalized[ $identifier ] = true;
		}

		return count( $normalized ) <= 1;
	}

	public function webhook_wc_order_id( WP_REST_Request $request ): int {
		$route_params = $request->get_url_params();
		$route_value  = is_array( $route_params ) ? ( $route_params['wc_order_id'] ?? null ) : null;
		$route_id     = is_scalar( $route_value ) ? NumericIdentifier::positive( trim( (string) $route_value ) ) : null;
		if ( null !== $route_id ) {
			return $route_id;
		}

		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			return 0;
		}

		foreach ( array( 'wc_order_id', 'woo_order_id', 'woocommerce_order_id' ) as $key ) {
			$value = $params[ $key ] ?? null;
			$id    = is_scalar( $value ) ? NumericIdentifier::positive( trim( (string) $value ) ) : null;
			if ( null !== $id ) {
				return $id;
			}
		}

		return 0;
	}

	public function webhook_order_reference( WP_REST_Request $request ): string {
		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			return '';
		}

		foreach ( array( 'order_reference', 'orderReference', 'soocool_order_reference', 'reference' ) as $key ) {
			$value = $params[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}

}
