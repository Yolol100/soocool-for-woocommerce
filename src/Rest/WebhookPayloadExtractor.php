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
	private const ALLOWED_STATUSES = array(
		'synced',
		'pending',
		'failed',
		'cancelled',
		'soocool_accepted',
		'soocool_active',
		'soocool_cancelled',
		'soocool_completed',
		'soocool_created',
		'soocool_delivered',
		'soocool_failed',
		'soocool_in_progress',
		'soocool_in_transit',
		'soocool_pending',
		'soocool_planned',
		'soocool_processing',
		'soocool_ready',
		'soocool_rejected',
		'soocool_shipped',
	);

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
			'tracking_code' => $this->extract_tracking_text( $payload, array( 'trackingCode', 'trackAndTrace', 'trackingNumber', 'tracking' ), array( 'code', 'trackingCode', 'trackingNumber', 'trackAndTrace' ) ),
			'tracking_url'  => $this->extract_tracking_url( $payload, array( 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl' ), array( 'url', 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl' ) ),
		);
	}


	/** @param array<string, mixed> $payload */
	private function status_from_payload( array $payload ): string {
		foreach ( $this->status_containers( $payload ) as $container ) {
			$cancelled = $container['cancelled'] ?? null;
			if ( true === $cancelled || ( is_scalar( $cancelled ) && ( 'true' === strtolower( trim( (string) $cancelled ) ) || '1' === trim( (string) $cancelled ) ) ) ) {
				return 'soocool_cancelled';
			}
		}

		foreach ( $this->status_containers( $payload ) as $container ) {
			foreach ( array( 'status', 'orderStatus', 'state', 'taskState' ) as $key ) {
				if ( isset( $container[ $key ] ) && is_scalar( $container[ $key ] ) && '' !== trim( (string) $container[ $key ] ) ) {
					return $this->normalize_status( sanitize_text_field( (string) $container[ $key ] ) );
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function status_containers( array $payload ): array {
		$containers = array( $payload );
		foreach ( array( 'order', 'data' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$containers[] = $payload[ $key ];
			}
		}

		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) ) {
			foreach ( array( 'order' ) as $key ) {
				if ( isset( $payload['data'][ $key ] ) && is_array( $payload['data'][ $key ] ) ) {
					$containers[] = $payload['data'][ $key ];
				}
			}
		}

		return $containers;
	}


	/** @param array<string, mixed> $payload @param array<int, string> $direct_keys @param array<int, string> $nested_keys */
	private function extract_tracking_text( array $payload, array $direct_keys, array $nested_keys ): string {
		$value = $this->extract_text( $payload, $direct_keys );
		if ( '' !== $value ) {
			return $value;
		}

		foreach ( $this->tracking_containers( $payload ) as $container ) {
			foreach ( $nested_keys as $key ) {
				if ( isset( $container[ $key ] ) && is_scalar( $container[ $key ] ) && '' !== trim( (string) $container[ $key ] ) ) {
					return sanitize_text_field( (string) $container[ $key ] );
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $direct_keys @param array<int, string> $nested_keys */
	private function extract_tracking_url( array $payload, array $direct_keys, array $nested_keys ): string {
		$value = $this->extract_url( $payload, $direct_keys );
		if ( '' !== $value ) {
			return $value;
		}

		foreach ( $this->tracking_containers( $payload ) as $container ) {
			foreach ( $nested_keys as $key ) {
				if ( isset( $container[ $key ] ) && is_scalar( $container[ $key ] ) && '' !== trim( (string) $container[ $key ] ) ) {
					$url = esc_url_raw( (string) $container[ $key ] );
					if ( false !== wp_http_validate_url( $url ) ) {
						return $url;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function tracking_containers( array $payload, int $depth = 0 ): array {
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			return array();
		}

		$containers = array();
		foreach ( array( 'tracking', 'trackAndTrace', 'shipment' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$containers[] = $payload[ $key ];
			}
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$containers = array_merge( $containers, $this->tracking_containers( $value, $depth + 1 ) );
			}
		}

		return $containers;
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

		$status = str_starts_with( $status, 'soocool_' ) ? $status : 'soocool_' . $status;

		return in_array( $status, $this->allowed_statuses(), true ) ? $status : '';
	}

	/** @return array<int, string> */
	private function allowed_statuses(): array {
		/**
		 * Filter accepted SooCool webhook statuses after sanitize_key() and soocool_ prefix normalization.
		 *
		 * Unknown values are ignored so a webhook cannot write arbitrary status strings
		 * into WooCommerce order meta.
		 *
		 * @param array<int, string> $statuses Allowed normalized status values.
		 */
		$statuses = apply_filters( 'soocool_allowed_webhook_statuses', self::ALLOWED_STATUSES );
		if ( ! is_array( $statuses ) ) {
			$statuses = self::ALLOWED_STATUSES;
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( mixed $status ): string => sanitize_key( (string) $status ),
						$statuses
					)
				)
			)
		);
	}
}

