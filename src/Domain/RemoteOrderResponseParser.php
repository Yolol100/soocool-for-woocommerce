<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\WooCommerce\OrderMeta;

defined( 'ABSPATH' ) || exit;

final class RemoteOrderResponseParser {

	private const MAX_RESPONSE_DEPTH  = 5;
	private const MAX_RESPONSE_ORDERS = 100;

	public function __construct( private readonly OrderMeta $meta ) {}

	/**
	 * @param array<mixed> $body
	 * @return array<int, array<string, mixed>>
	 */
	public function candidates( array $body, bool $allow_reference_only = false ): array {
		$candidates = array();
		$this->collect_candidates( $body, $candidates, $allow_reference_only );

		return $candidates;
	}

	/** @param array<string, mixed> $remote_order */
	public function reference( array $remote_order ): string {
		foreach ( array( 'orderReference', 'ourReference', 'reference' ) as $key ) {
			if ( isset( $remote_order[ $key ] ) && is_scalar( $remote_order[ $key ] ) ) {
				$reference = trim( sanitize_text_field( (string) $remote_order[ $key ] ) );
				if ( '' !== $reference ) {
					return $reference;
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $remote_order */
	public function fingerprint( array $remote_order ): string {
		$order_id = $this->meta->extract_order_id( $remote_order );
		if ( '' !== $order_id ) {
			return 'id:' . $order_id;
		}

		return hash( 'sha256', wp_json_encode( $remote_order ) ?: serialize( $remote_order ) );
	}

	/**
	 * @param array<mixed> $value
	 * @param array<int, array<string, mixed>> $candidates
	 */
	private function collect_candidates( array $value, array &$candidates, bool $allow_reference_only, int $depth = 0 ): void {
		if ( $depth > self::MAX_RESPONSE_DEPTH || count( $candidates ) >= self::MAX_RESPONSE_ORDERS ) {
			return;
		}

		if ( ! array_is_list( $value ) ) {
			$has_order_id = '' !== $this->meta->extract_order_id( $value );
			$has_reference = $allow_reference_only && '' !== $this->reference( $value );
			if ( $has_order_id || $has_reference ) {
				$candidates[] = $value;
			}
		}

		foreach ( $value as $nested ) {
			if ( ! is_array( $nested ) ) {
				continue;
			}

			$this->collect_candidates( $nested, $candidates, $allow_reference_only, $depth + 1 );
			if ( count( $candidates ) >= self::MAX_RESPONSE_ORDERS ) {
				break;
			}
		}
	}
}
