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
			'tracking_code' => $this->extract_tracking_text( $body, array( 'trackingCode', 'trackAndTrace', 'trackingNumber', 'tracking' ), array( 'code', 'trackingCode', 'trackingNumber', 'trackAndTrace' ) ),
			'tracking_url'  => $this->extract_tracking_url( $body, array( 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl' ), array( 'url', 'trackingUrl', 'trackAndTraceUrl', 'trackAndTraceLink', 'traceUrl' ) ),
		);
	}


	/** @param array<string, mixed> $body */
	private function status_from_body( array $body ): string {
		foreach ( $this->status_candidates( $body ) as $candidate ) {
			$cancelled = $candidate['cancelled'] ?? null;
			if ( true === $cancelled || ( is_scalar( $cancelled ) && ( 'true' === strtolower( trim( (string) $cancelled ) ) || '1' === trim( (string) $cancelled ) ) ) ) {
				return 'soocool_cancelled';
			}
		}

		foreach ( $this->status_candidates( $body ) as $candidate ) {
			foreach ( array( 'status', 'orderStatus', 'state', 'taskState' ) as $key ) {
				if ( isset( $candidate[ $key ] ) && ! is_array( $candidate[ $key ] ) && ! is_object( $candidate[ $key ] ) ) {
					$value = trim( sanitize_text_field( (string) $candidate[ $key ] ) );
					if ( '' !== $value ) {
						return $this->normalize_remote_status( $value );
					}
				}
			}
		}

		return '';
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


	/** @param array<string, mixed> $payload @param array<int, string> $direct_keys @param array<int, string> $nested_keys */
	private function extract_tracking_text( array $payload, array $direct_keys, array $nested_keys ): string {
		$value = $this->extract_text( $payload, $direct_keys );
		if ( '' !== $value ) {
			return $value;
		}

		foreach ( $this->tracking_containers( $payload ) as $container ) {
			foreach ( $nested_keys as $key ) {
				if ( isset( $container[ $key ] ) && ! is_array( $container[ $key ] ) && ! is_object( $container[ $key ] ) ) {
					$value = trim( sanitize_text_field( (string) $container[ $key ] ) );
					if ( '' !== $value ) {
						return $value;
					}
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
				if ( isset( $container[ $key ] ) && ! is_array( $container[ $key ] ) && ! is_object( $container[ $key ] ) ) {
					$url = esc_url_raw( (string) $container[ $key ] );
					if ( '' !== $url && false !== wp_http_validate_url( $url ) ) {
						return $url;
					}
				}
			}
		}

		return '';
	}

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function tracking_containers( array $payload, int $depth = 0 ): array {
		if ( $depth > self::MAX_RESPONSE_NESTING_DEPTH ) {
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

	/** @param array<string, mixed> $payload @return array<int, array<string, mixed>> */
	private function status_candidates( array $payload ): array {
		$candidates = array( $payload );
		foreach ( array( 'order', 'data' ) as $key ) {
			if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] ) ) {
				$candidates[] = $payload[ $key ];
			}
		}

		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) && isset( $payload['data']['order'] ) && is_array( $payload['data']['order'] ) ) {
			$candidates[] = $payload['data']['order'];
		}

		return $candidates;
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
