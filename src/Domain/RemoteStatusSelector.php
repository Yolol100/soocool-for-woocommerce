<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

defined( 'ABSPATH' ) || exit;

final class RemoteStatusSelector {

	private readonly RemoteStatusPolicy $statuses;

	public function __construct( ?RemoteStatusPolicy $statuses = null ) {
		$this->statuses = $statuses ?? new RemoteStatusPolicy();
	}

	/** @param array<int, array<string, mixed>> $containers */
	public function select( array $containers ): string {
		foreach ( $containers as $container ) {
			if ( $this->has_task_scope( $container ) ) {
				continue;
			}

			$cancelled = $container['cancelled'] ?? null;
			if ( $this->is_true( $cancelled ) ) {
				return 'soocool_cancelled';
			}
		}

		foreach ( $containers as $container ) {
			if ( $this->has_task_scope( $container ) ) {
				continue;
			}

			foreach ( array( 'status', 'orderStatus', 'state' ) as $key ) {
				$status = $this->normalized_status( $container[ $key ] ?? null );
				if ( '' !== $status ) {
					return $status;
				}
			}
		}

		$delivery_statuses = array();
		foreach ( $containers as $container ) {
			if ( 'delivery' !== $this->task_type( $container ) ) {
				continue;
			}

			if ( $this->is_true( $container['cancelled'] ?? null ) ) {
				$delivery_statuses['soocool_cancelled'] = true;
			}

			foreach ( array( 'taskState', 'task_state', 'status', 'state' ) as $key ) {
				$status = $this->normalized_status( $container[ $key ] ?? null );
				if ( '' !== $status ) {
					$delivery_statuses[ $status ] = true;
				}
			}
		}

		return 1 === count( $delivery_statuses ) ? (string) array_key_first( $delivery_statuses ) : '';
	}

	/** @param array<string, mixed> $container */
	private function has_task_scope( array $container ): bool {
		if ( '' !== $this->task_type( $container ) ) {
			return true;
		}

		foreach ( array( 'taskState', 'task_state' ) as $key ) {
			if ( array_key_exists( $key, $container ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $container */
	private function task_type( array $container ): string {
		foreach ( array( 'taskType', 'task_type' ) as $key ) {
			if ( ! isset( $container[ $key ] ) || ! is_scalar( $container[ $key ] ) ) {
				continue;
			}

			$value = sanitize_key( strtolower( trim( (string) $container[ $key ] ) ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function normalized_status( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		return '' !== $value ? $this->statuses->normalize_remote( $value ) : '';
	}

	private function is_true( mixed $value ): bool {
		if ( true === $value ) {
			return true;
		}
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$value = strtolower( trim( (string) $value ) );
		return 'true' === $value || '1' === $value;
	}
}
