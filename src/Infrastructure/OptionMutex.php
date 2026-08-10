<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class OptionMutex {

	public static function acquire( string $key, int $ttl_seconds ): ?string {
		if ( '' === $key || 0 >= $ttl_seconds ) {
			return null;
		}

		$missing = new \stdClass();
		$stored  = get_option( $key, $missing );
		$exists  = $stored !== $missing;
		$current = $exists ? maybe_serialize( $stored ) : '';
		$now     = time();

		if ( is_scalar( $stored ) && self::expiration( (string) $stored ) > $now ) {
			return null;
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$value = ( $now + $ttl_seconds ) . '|' . $token;

		$acquired = ! $exists
			? add_option( $key, $value, '', false )
			: self::compare_and_swap( $key, $current, $value );

		return $acquired ? $value : null;
	}

	public static function refresh( string $key, string $value, int $ttl_seconds ): ?string {
		if ( '' === $key || '' === $value || 0 >= $ttl_seconds ) {
			return null;
		}

		$parts = explode( '|', $value, 2 );
		$token = isset( $parts[1] ) ? trim( $parts[1] ) : '';
		if ( '' === $token ) {
			return null;
		}

		$current_expiration = self::expiration( $value );
		$new_expiration     = time() + $ttl_seconds;
		if ( 0 < $current_expiration ) {
			// wpdb::update() reports 0 for a no-op update, so keep each refresh value distinct.
			$new_expiration = max( $new_expiration, $current_expiration + 1 );
		}

		$replacement = $new_expiration . '|' . $token;
		return self::compare_and_swap( $key, maybe_serialize( $value ), $replacement ) ? $replacement : null;
	}

	public static function release( string $key, string $value ): bool {
		if ( '' === $key || '' === $value ) {
			return false;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'delete' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional deletion prevents an expired owner from deleting a newer lock.
		$deleted = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $key,
				'option_value' => $value,
			),
			array( '%s', '%s' )
		);
		if ( 1 !== (int) $deleted ) {
			return false;
		}

		wp_cache_delete( $key, 'options' );
		return true;
	}

	private static function compare_and_swap( string $key, string $expected, string $replacement ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'update' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-swap is required for safe expired-lock takeover.
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $replacement ),
			array(
				'option_name'  => $key,
				'option_value' => $expected,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			return false;
		}

		wp_cache_delete( $key, 'options' );
		return true;
	}

	private static function expiration( string $value ): int {
		$parts = explode( '|', $value, 2 );
		return isset( $parts[0] ) ? ( NumericIdentifier::positive( $parts[0] ) ?? 0 ) : 0;
	}
}
