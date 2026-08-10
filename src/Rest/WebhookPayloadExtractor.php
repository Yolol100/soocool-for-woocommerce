<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Domain\RemoteStatusPolicy;
use SooCool\WooCommerce\Infrastructure\HttpsUrl;
use SooCool\WooCommerce\Infrastructure\NumericIdentifier;

defined( 'ABSPATH' ) || exit;

final class WebhookPayloadExtractor {

	public const MAX_PAYLOAD_BYTES = 262144;

	private const MAX_NESTING_DEPTH          = 5;
	private const MAX_ARRAY_ITEMS            = 250;
	private const MAX_TOTAL_ARRAY_ITEMS      = 1000;
	private const MAX_TRACKING_CONTAINERS    = 100;
	private const PREFERRED_VALUE_CONTAINERS = array( 'order', 'shipment', 'tracking', 'trackAndTrace', 'data' );
	private const EVENT_SEQUENCE_KEYS = array( 'eventSequence', 'event_sequence', 'eventVersion', 'event_version', 'sequenceNumber', 'sequence_number' );
	private const EVENT_TIMESTAMP_KEYS = array( 'eventTimestamp', 'event_timestamp', 'eventTime', 'event_time', 'occurredAt', 'occurred_at', 'occurredOn', 'occurred_on' );

	private readonly RemoteStatusPolicy $statuses;

	public function __construct( ?RemoteStatusPolicy $statuses = null ) {
		$this->statuses = $statuses ?? new RemoteStatusPolicy();
	}

	/** @param array<string, mixed> $payload */
	public function shape_is_safe( array $payload ): bool {
		$total_items = 0;
		return $this->payload_shape_is_safe( $payload, 0, $total_items );
	}

	/** @param array<string, mixed> $payload */
	public function identifiers_are_consistent( array $payload ): bool {
		return $this->unique_positive_identifiers( $payload, array( 'orderId', 'soocoolOrderId' ) )
			&& $this->unique_positive_identifiers(
				$payload,
				array( 'wcOrderId', 'wc_order_id', 'wooOrderId', 'woo_order_id', 'woocommerceOrderId', 'woocommerce_order_id' )
			)
			&& $this->order_references_are_consistent( $payload );
	}

	/** @param array<string, mixed> $payload */
	public function soocool_order_id( array $payload ): string {
		return $this->extract_positive_identifier( $payload, array( 'orderId', 'soocoolOrderId' ) );
	}

	/** @param array<string, mixed> $payload */
	public function order_reference( array $payload ): string {
		$reference = $this->extract_text( $payload, array( 'orderReference', 'ourReference' ) );
		return '' !== $reference ? $reference : $this->scoped_order_reference( $payload );
	}

	/** @param array<string, mixed> $payload */
	public function wc_order_id( array $payload ): int {
		$value = $this->extract_positive_identifier(
			$payload,
			array(
				'wcOrderId',
				'wc_order_id',
				'wooOrderId',
				'woo_order_id',
				'woocommerceOrderId',
				'woocommerce_order_id',
			)
		);

		return '' !== $value ? (int) $value : 0;
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
	public function event_ordering_is_consistent( array $payload ): bool {
		return $this->normalized_event_values_are_consistent( $payload, self::EVENT_SEQUENCE_KEYS, array( $this, 'normalize_event_sequence' ) )
			&& $this->normalized_event_values_are_consistent( $payload, self::EVENT_TIMESTAMP_KEYS, array( $this, 'normalize_event_timestamp' ) );
	}

	/** @param array<string, mixed> $payload */
	public function event_sequence( array $payload ): int {
		return $this->first_normalized_event_value( $payload, self::EVENT_SEQUENCE_KEYS, array( $this, 'normalize_event_sequence' ) );
	}

	/** @param array<string, mixed> $payload */
	public function event_timestamp( array $payload ): int {
		return $this->first_normalized_event_value( $payload, self::EVENT_TIMESTAMP_KEYS, array( $this, 'normalize_event_timestamp' ) );
	}

	/** @param array<string, mixed> $payload */
	private function status_from_payload( array $payload ): string {
		$containers = $this->status_containers( $payload );
		foreach ( $containers as $container ) {
			$cancelled = $container['cancelled'] ?? null;
			if ( true === $cancelled || ( is_scalar( $cancelled ) && ( 'true' === strtolower( trim( (string) $cancelled ) ) || '1' === trim( (string) $cancelled ) ) ) ) {
				return 'soocool_cancelled';
			}
		}

		foreach ( $containers as $container ) {
			foreach ( array( 'status', 'orderStatus', 'state', 'taskState' ) as $key ) {
				if ( ! isset( $container[ $key ] ) || ! is_scalar( $container[ $key ] ) || '' === trim( (string) $container[ $key ] ) ) {
					continue;
				}

				$status = $this->statuses->normalize_remote( sanitize_text_field( (string) $container[ $key ] ) );
				if ( '' !== $status ) {
					return $status;
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function status_containers( array $payload ): array {
		$containers = array();
		$this->collect_containers( $payload, $containers );
		return $containers;
	}

	/** @param array<string, mixed> $payload @param array<int, array<string, mixed>> $containers */
	private function collect_containers( array $payload, array &$containers, int $depth = 0 ): void {
		if ( $depth > self::MAX_NESTING_DEPTH || count( $containers ) >= self::MAX_TOTAL_ARRAY_ITEMS ) {
			return;
		}

		$containers[] = $payload;
		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_containers( $value, $containers, $depth + 1 );
			}
		}
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
					$url = HttpsUrl::sanitize( $container[ $key ] );
					if ( '' !== $url ) {
						return $url;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function tracking_containers( array $payload ): array {
		$containers = array();
		$this->collect_tracking_containers( $payload, $containers );
		return $containers;
	}

	/** @param array<string, mixed> $payload @param array<int, array<string, mixed>> $containers */
	private function collect_tracking_containers( array $payload, array &$containers, int $depth = 0 ): void {
		if ( $depth > self::MAX_NESTING_DEPTH || count( $containers ) >= self::MAX_TRACKING_CONTAINERS ) {
			return;
		}

		foreach ( array( 'tracking', 'trackAndTrace', 'shipment' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$containers[] = $payload[ $key ];
				if ( count( $containers ) >= self::MAX_TRACKING_CONTAINERS ) {
					return;
				}
			}
		}

		foreach ( $payload as $value ) {
			if ( is_array( $value ) ) {
				$this->collect_tracking_containers( $value, $containers, $depth + 1 );
				if ( count( $containers ) >= self::MAX_TRACKING_CONTAINERS ) {
					return;
				}
			}
		}
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
	private function extract_positive_identifier( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->deep_normalized_value(
				$payload,
				$key,
				static function ( mixed $candidate ): string {
					$id = NumericIdentifier::positive( $candidate );

					return null !== $id ? (string) $id : '';
				}
			);
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_text( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->deep_text_value( $payload, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_url( array $payload, array $keys ): string {
		foreach ( $keys as $key ) {
			$url = $this->deep_url_value( $payload, $key );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload */
	private function deep_text_value( array $payload, string $key ): string {
		return $this->deep_normalized_value(
			$payload,
			$key,
			static function ( mixed $value ): string {
				if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
					return '';
				}

				return sanitize_text_field( (string) $value );
			}
		);
	}

	/** @param array<string, mixed> $payload */
	private function deep_url_value( array $payload, string $key ): string {
		return $this->deep_normalized_value(
			$payload,
			$key,
			static function ( mixed $value ): string {
				if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
					return '';
				}

				return HttpsUrl::sanitize( $value );
			}
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param callable(mixed): string $normalizer
	 */
	private function deep_normalized_value( array $payload, string $key, callable $normalizer, int $depth = 0 ): string {
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			return '';
		}

		if ( array_key_exists( $key, $payload ) ) {
			$value = $normalizer( $payload[ $key ] );
			if ( '' !== $value ) {
				return $value;
			}
		}

		foreach ( self::PREFERRED_VALUE_CONTAINERS as $container ) {
			if ( ! isset( $payload[ $container ] ) || ! is_array( $payload[ $container ] ) ) {
				continue;
			}

			$value = $this->deep_normalized_value( $payload[ $container ], $key, $normalizer, $depth + 1 );
			if ( '' !== $value ) {
				return $value;
			}
		}

		foreach ( $payload as $container => $nested ) {
			if ( ! is_array( $nested ) || in_array( (string) $container, self::PREFERRED_VALUE_CONTAINERS, true ) ) {
				continue;
			}

			$value = $this->deep_normalized_value( $nested, $key, $normalizer, $depth + 1 );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function unique_positive_identifiers( array $payload, array $keys ): bool {
		$values = array();
		$this->collect_identifier_values( $payload, $keys, $values );
		$normalized = array();
		foreach ( $values as $value ) {
			if ( null === $value || ( is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				return false;
			}

			$id = NumericIdentifier::positive( $value );
			if ( null === $id ) {
				return false;
			}
			$normalized[ (string) $id ] = true;
		}

		return count( $normalized ) <= 1;
	}

	/** @param array<string, mixed> $payload */
	private function order_references_are_consistent( array $payload ): bool {
		$values = array();
		$this->collect_identifier_values( $payload, array( 'orderReference', 'ourReference' ), $values );
		$this->collect_scoped_order_reference_values( $payload, $values );

		return $this->text_identifier_values_are_consistent( $values );
	}

	/** @param array<string, mixed> $payload */
	private function scoped_order_reference( array $payload, int $depth = 0 ): string {
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			return '';
		}

		if ( array_key_exists( 'reference', $payload ) && is_scalar( $payload['reference'] ) ) {
			$reference = trim( sanitize_text_field( (string) $payload['reference'] ) );
			if ( '' !== $reference ) {
				return $reference;
			}
		}

		foreach ( array( 'order', 'data' ) as $container ) {
			if ( ! isset( $payload[ $container ] ) || ! is_array( $payload[ $container ] ) ) {
				continue;
			}

			$reference = $this->scoped_order_reference( $payload[ $container ], $depth + 1 );
			if ( '' !== $reference ) {
				return $reference;
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, mixed> $values */
	private function collect_scoped_order_reference_values( array $payload, array &$values, int $depth = 0 ): void {
		if ( $depth > self::MAX_NESTING_DEPTH || count( $values ) >= self::MAX_TOTAL_ARRAY_ITEMS ) {
			return;
		}

		if ( array_key_exists( 'reference', $payload ) ) {
			$values[] = $payload['reference'];
		}

		foreach ( array( 'order', 'data' ) as $container ) {
			if ( isset( $payload[ $container ] ) && is_array( $payload[ $container ] ) ) {
				$this->collect_scoped_order_reference_values( $payload[ $container ], $values, $depth + 1 );
			}
		}
	}

	/** @param array<int, mixed> $values */
	private function text_identifier_values_are_consistent( array $values ): bool {
		$normalized = array();
		foreach ( $values as $value ) {
			if ( null === $value || ( is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				return false;
			}

			$text = trim( sanitize_text_field( (string) $value ) );
			if ( '' === $text ) {
				return false;
			}
			$normalized[ $text ] = true;
		}

		return count( $normalized ) <= 1;
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys @param array<int, mixed> $values */
	private function collect_identifier_values( array $payload, array $keys, array &$values, int $depth = 0 ): void {
		if ( $depth > self::MAX_NESTING_DEPTH || count( $values ) >= self::MAX_TOTAL_ARRAY_ITEMS ) {
			return;
		}

		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$values[] = $payload[ $key ];
			}
		}
		foreach ( $payload as $nested ) {
			if ( is_array( $nested ) ) {
				$this->collect_identifier_values( $nested, $keys, $values, $depth + 1 );
			}
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, string>   $keys
	 * @param callable(mixed): int $normalizer
	 */
	private function normalized_event_values_are_consistent( array $payload, array $keys, callable $normalizer ): bool {
		$values = array();
		$this->collect_identifier_values( $payload, $keys, $values );
		$normalized = array();

		foreach ( $values as $value ) {
			if ( null === $value || ( is_scalar( $value ) && '' === trim( (string) $value ) ) ) {
				continue;
			}
			$number = $normalizer( $value );
			if ( 0 >= $number ) {
				return false;
			}
			$normalized[ (string) $number ] = true;
		}

		return count( $normalized ) <= 1;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<int, string>   $keys
	 * @param callable(mixed): int $normalizer
	 */
	private function first_normalized_event_value( array $payload, array $keys, callable $normalizer ): int {
		$values = array();
		$this->collect_identifier_values( $payload, $keys, $values );
		foreach ( $values as $value ) {
			$number = $normalizer( $value );
			if ( 0 < $number ) {
				return $number;
			}
		}

		return 0;
	}

	private function normalize_event_sequence( mixed $value ): int {
		return NumericIdentifier::positive( $value ) ?? 0;
	}

	private function normalize_event_timestamp( mixed $value ): int {
		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d{1,16}$/', trim( $value ) ) ) ) {
			$timestamp = NumericIdentifier::positive( $value ) ?? 0;
			while ( $timestamp > 20000000000 ) {
				$timestamp = intdiv( $timestamp, 1000 );
			}

			return $timestamp;
		}
		if ( ! is_string( $value ) ) {
			return 0;
		}

		$value = trim( $value );
		if ( 1 !== preg_match(
			'/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,6})?(Z|[+-]((?:0\d|1[0-3]):[0-5]\d|14:00))$/',
			$value,
			$parts
		) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
			return 0;
		}

		try {
			return ( new \DateTimeImmutable( $value ) )->getTimestamp();
		} catch ( \Exception ) {
			return 0;
		}
	}

}

