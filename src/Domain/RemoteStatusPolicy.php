<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

defined( 'ABSPATH' ) || exit;

final class RemoteStatusPolicy {

	private const STATUS_RANKS = array(
		'synced'              => 10,
		'pending'             => 20,
		'soocool_pending'     => 20,
		'soocool_created'     => 20,
		'soocool_accepted'    => 30,
		'soocool_planned'     => 30,
		'soocool_ready'       => 30,
		'soocool_processing'  => 30,
		'soocool_active'      => 30,
		'soocool_in_progress' => 30,
		'soocool_shipped'     => 40,
		'soocool_in_transit'  => 40,
		'soocool_delivered'   => 50,
		'failed'              => 60,
		'cancelled'           => 60,
		'soocool_cancelled'   => 60,
		'soocool_failed'      => 60,
		'soocool_rejected'    => 60,
		'soocool_completed'   => 70,
	);

	private const CANCELLABLE_STATUSES = array(
		'synced',
		'pending',
		'soocool_pending',
		'soocool_created',
		'soocool_accepted',
		'soocool_planned',
		'soocool_ready',
		'soocool_processing',
		'soocool_active',
		'soocool_in_progress',
	);

	private const IMMUTABLE_TERMINAL_STATUSES = array(
		'failed',
		'cancelled',
		'soocool_cancelled',
		'soocool_failed',
		'soocool_rejected',
		'soocool_completed',
	);

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

	public function normalize( string $status ): string {
		$status = preg_replace( '/(?<=[a-z0-9])(?=[A-Z])/', '_', trim( $status ) ) ?? trim( $status );
		$status = preg_replace( '/[\s-]+/', '_', $status ) ?? $status;
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $status ) ) {
			return '';
		}

		$status = sanitize_key( strtolower( $status ) );
		if ( '' === $status ) {
			return '';
		}

		if ( in_array( $status, array( 'synced', 'pending', 'failed', 'cancelled' ), true ) ) {
			return $status;
		}

		$status = str_starts_with( $status, 'soocool_' ) ? $status : 'soocool_' . $status;
		return in_array( $status, $this->allowed_statuses(), true ) ? $status : '';
	}

	public function normalize_remote( string $status ): string {
		$status = $this->normalize( $status );
		return match ( $status ) {
			'pending'   => 'soocool_pending',
			'failed'    => 'soocool_failed',
			'cancelled' => 'soocool_cancelled',
			default     => $status,
		};
	}

	public function allows_remote_cancel( string $status ): bool {
		$status = $this->normalize( $status );
		if ( '' === $status ) {
			return false;
		}

		$statuses = apply_filters( 'soocool_cancellable_remote_statuses', self::CANCELLABLE_STATUSES );
		if ( ! is_array( $statuses ) ) {
			$statuses = self::CANCELLABLE_STATUSES;
		}

		$allowed = array();
		foreach ( $statuses as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}

			$normalized = $this->normalize( (string) $candidate );
			if ( '' !== $normalized ) {
				$allowed[] = $normalized;
			}
		}

		return in_array( $status, array_values( array_unique( $allowed ) ), true );
	}

	public function allows_transition( string $current, string $next ): bool {
		$current = $this->normalize( $current );
		$next    = $this->normalize( $next );

		if ( '' === $next ) {
			return false;
		}
		if ( '' === $current || $current === $next ) {
			return true;
		}
		if ( 'failed' === $current ) {
			return 'failed' !== $next;
		}
		if ( in_array( $current, self::IMMUTABLE_TERMINAL_STATUSES, true ) ) {
			return false;
		}
		if ( 'soocool_delivered' === $current ) {
			return 'soocool_completed' === $next;
		}

		$current_rank = self::STATUS_RANKS[ $current ] ?? -1;
		$next_rank    = self::STATUS_RANKS[ $next ] ?? -1;

		return 0 <= $current_rank && $next_rank >= $current_rank;
	}

	/** @return array<int, string> */
	private function allowed_statuses(): array {
		$statuses = apply_filters( 'soocool_allowed_webhook_statuses', self::ALLOWED_STATUSES );
		$statuses = apply_filters( 'soocool_allowed_remote_statuses', $statuses );
		if ( ! is_array( $statuses ) ) {
			$statuses = self::ALLOWED_STATUSES;
		}

		$normalized = array();
		foreach ( $statuses as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$status = trim( (string) $value );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $status ) ) {
				continue;
			}

			$status = sanitize_key( strtolower( $status ) );
			if ( '' !== $status ) {
				$normalized[] = $status;
			}
		}

		return array_values( array_unique( $normalized ) );
	}
}
