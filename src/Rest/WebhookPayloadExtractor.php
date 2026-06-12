<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookPayloadExtractor {

	public const MAX_PAYLOAD_BYTES = 262144;
	private const MAX_NESTING_DEPTH = 5;
	private const MAX_ARRAY_ITEMS   = 250;
	private const MAX_TOTAL_ARRAY_ITEMS = 1000;

	/** @param array<string, mixed> $payload */
	public function shape_is_safe( array $payload ): bool {
		$total_items = 0;
		return $this->payload_shape_is_safe( $payload, 0, $total_items );
	}

	/** @param array<string, mixed> $payload */
	public function soocool_order_id( array $payload ): string {
		$value = $this->extract_text( $payload, array( 'orderId', 'soocoolOrderId' ) );
		return ctype_digit( $value ) && 0 < (int) $value ? (string) (int) $value : '';
	}

	/** @param array<string, mixed> $payload */
	public function order_reference( array $payload ): string {
		return $this->extract_text( $payload, array( 'orderReference', 'ourReference', 'reference' ) );
	}

	/** @param array<string, mixed> $payload @return array<string, string> */
	public function update_data( array $payload ): array {
		return array(
			'status'        => $this->status_from_payload( $payload ),
			'tracking_code' => $this->extract_text( $payload, array( 'trackingCode', 'trackAndTrace', 'trackingNumber', 'tracking' ) ),
			'tracking_url'  => $this->extract_url( $payload, array( 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl' ) ),
		);
	}


	/** @param array<string, mixed> $payload */
	private function status_from_payload( array $payload ): string {
		$cancelled = $this->deep_value( $payload, 'cancelled' );
		if ( true === $cancelled || ( is_scalar( $cancelled ) && ( 'true' === strtolower( trim( (string) $cancelled ) ) || '1' === trim( (string) $cancelled ) ) ) ) {
			return 'soocool_cancelled';
		}

		return $this->normalize_status( $this->extract_text( $payload, array( 'status', 'orderStatus', 'state', 'taskState' ) ) );
	}

	/** @param array<string, mixed> $payload */
	private function payload_shape_is_safe( array $payload, int $depth, int &$total_items ): bool {
		$item_count = count( $payload );
		$total_items += $item_count;

		if ( $depth > self::MAX_NESTING_DEPTH || $item_count > self::MAX_ARRAY_ITEMS || $total_items > self::MAX_TOTAL_ARRAY_ITEMS ) {
			return false;
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) && ! $this->payload_shape_is_safe( $value, $depth + 1, $total_items ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_text( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->deep_value( $payload, $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_url( array $payload, array $keys ): string {
		$value = $this->extract_text( $payload, $keys );
		if ( '' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value );
		return false !== wp_http_validate_url( $url ) ? $url : '';
	}

	/** @param array<string, mixed> $payload */
	private function deep_value( array $payload, string $key, int $depth = 0 ): mixed {
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			return null;
		}

		if ( array_key_exists( $key, $payload ) ) {
			return $payload[ $key ];
		}

		foreach ( array( 'order', 'shipment', 'tracking', 'trackAndTrace', 'data' ) as $container ) {
			if ( isset( $payload[ $container ] ) && is_array( $payload[ $container ] ) && array_key_exists( $key, $payload[ $container ] ) ) {
				return $payload[ $container ][ $key ];
			}
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$nested = $this->deep_value( $value, $key, $depth + 1 );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	private function normalize_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return '';
		}

		return str_starts_with( $status, 'soocool_' ) ? $status : 'soocool_' . $status;
	}
}
