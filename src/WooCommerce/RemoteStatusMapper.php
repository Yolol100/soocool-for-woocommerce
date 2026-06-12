<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RemoteStatusMapper {

	private const MAX_RESPONSE_NESTING_DEPTH = 5;
	private const MAX_RESPONSE_CANDIDATES    = 100;
	private const MAX_RESPONSE_ARRAY_ITEMS   = 1000;

	/** @param array<string, mixed> $body @return array<string, string> */
	public function map( array $body ): array {
		return array(
			'status'        => $this->status_from_body( $body ),
			'tracking_code' => $this->extract_text( $body, array( 'trackingCode', 'trackAndTrace', 'trackingNumber', 'tracking' ) ),
			'tracking_url'  => $this->extract_url( $body, array( 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl' ) ),
		);
	}


	/** @param array<string, mixed> $body */
	private function status_from_body( array $body ): string {
		foreach ( $this->payload_candidates( $body ) as $candidate ) {
			$cancelled = $candidate['cancelled'] ?? null;
			if ( true === $cancelled || ( is_scalar( $cancelled ) && ( 'true' === strtolower( trim( (string) $cancelled ) ) || '1' === trim( (string) $cancelled ) ) ) ) {
				return 'soocool_cancelled';
			}
		}

		return $this->normalize_remote_status( $this->extract_text( $body, array( 'status', 'orderStatus', 'state', 'taskState' ) ) );
	}

	/** @param array<string, string> $data */
	public function has_status_data( array $data ): bool {
		return '' !== ( $data['status'] ?? '' ) || '' !== ( $data['tracking_code'] ?? '' ) || '' !== ( $data['tracking_url'] ?? '' );
	}

	private function normalize_remote_status( string $status ): string {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return '';
		}

		return str_starts_with( $status, 'soocool_' ) ? $status : 'soocool_' . $status;
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_text( array $payload, array $keys ): string {
		foreach ( $this->payload_candidates( $payload ) as $candidate ) {
			foreach ( $keys as $key ) {
				if ( isset( $candidate[ $key ] ) && ! is_array( $candidate[ $key ] ) && ! is_object( $candidate[ $key ] ) ) {
					$value = trim( sanitize_text_field( (string) $candidate[ $key ] ) );
					if ( '' !== $value ) {
						return $value;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @param array<int, string> $keys */
	private function extract_url( array $payload, array $keys ): string {
		foreach ( $this->payload_candidates( $payload ) as $candidate ) {
			foreach ( $keys as $key ) {
				if ( isset( $candidate[ $key ] ) && ! is_array( $candidate[ $key ] ) && ! is_object( $candidate[ $key ] ) ) {
					$value = esc_url_raw( (string) $candidate[ $key ] );
					if ( '' !== $value && false !== wp_http_validate_url( $value ) ) {
						return $value;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function payload_candidates( array $payload ): array {
		$candidates = array();
		$this->collect_payload_candidates( $payload, $candidates );

		return $candidates;
	}

	/**
	 * @param array<string, mixed>              $payload
	 * @param array<int, array<string, mixed>> $candidates
	 */
	private function collect_payload_candidates( array $payload, array &$candidates, int $depth = 0 ): void {
		if ( $depth > self::MAX_RESPONSE_NESTING_DEPTH || count( $candidates ) >= self::MAX_RESPONSE_CANDIDATES ) {
			return;
		}

		$candidates[] = $payload;

		$processed_items = 0;
		foreach ( $payload as $value ) {
			++$processed_items;
			if ( $processed_items > self::MAX_RESPONSE_ARRAY_ITEMS || count( $candidates ) >= self::MAX_RESPONSE_CANDIDATES ) {
				return;
			}
			if ( is_array( $value ) ) {
				$this->collect_payload_candidates( $value, $candidates, $depth + 1 );
			}
		}
	}
}
