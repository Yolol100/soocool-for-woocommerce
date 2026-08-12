<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\Infrastructure\OptionMutex;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class WebhookAuthenticator {

	private const SIGNATURE_HEADER = 'x-soocool-webhook-signature';
	private const TIMESTAMP_HEADER = 'x-soocool-webhook-timestamp';
	private const EVENT_ID_HEADER  = 'x-soocool-webhook-id';
	private const SIGNATURE_TOLERANCE_SECONDS = 300;
	private const MAX_SIGNED_BODY_BYTES       = 262144;
	private const RESERVATION_PREFIX           = 'soocool_webhook_reservation_';
	private const RESERVATION_TTL_SECONDS      = 300;
	private const PROCESSED_PREFIX             = 'soocool_webhook_event_';
	private const PROCESSED_TTL_SECONDS        = 604800;
	private const CLEANUP_HOOK                 = 'soocool_cleanup_webhook_events';
	private const CLEANUP_BATCH_HOOK           = 'soocool_cleanup_webhook_events_batch';
	private const CLEANUP_BATCH_SIZE           = 500;
	private const CLEANUP_OPTION_PREFIXES      = array(
		self::PROCESSED_PREFIX,
		self::RESERVATION_PREFIX,
		'soocool_webhook_order_lock_',
		'soocool_sync_lock_',
		'soocool_bulk_label_lock_',
		'soocool_settings_write_lock',
		'soocool_logs_write_lock',
	);

	/** @var \WeakMap<WP_REST_Request, array{key: string, value: string}> */
	private \WeakMap $reservations;

	public function __construct( private readonly OptionRepository $options ) {
		$this->reservations = new \WeakMap();
	}


	public function register_cleanup(): void {
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup_expired_processed_deliveries' ) );
		add_action( self::CLEANUP_BATCH_HOOK, array( $this, 'cleanup_expired_processed_deliveries' ) );

		if ( false === wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function unschedule_cleanup(): void {
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_BATCH_HOOK );
	}

	public function cleanup_expired_processed_deliveries(): int {
		global $wpdb;
		if (
			! is_object( $wpdb )
			|| ! isset( $wpdb->options )
			|| ! method_exists( $wpdb, 'esc_like' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_results' )
		) {
			return 0;
		}

		$deleted  = 0;
		$has_more = false;
		$now      = time();

		foreach ( self::CLEANUP_OPTION_PREFIXES as $prefix ) {
			$like = $wpdb->esc_like( $prefix ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup of plugin-owned mutex/replay options requires a prefix query.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d ORDER BY option_id ASC LIMIT %d",
					$like,
					$now,
					self::CLEANUP_BATCH_SIZE
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) ) {
				continue;
			}

			if ( self::CLEANUP_BATCH_SIZE === count( $rows ) ) {
				$has_more = true;
			}

			foreach ( $rows as $row ) {
				$key   = is_array( $row ) && is_scalar( $row['option_name'] ?? null ) ? (string) $row['option_name'] : '';
				$value = is_array( $row ) && is_scalar( $row['option_value'] ?? null ) ? (string) $row['option_value'] : '';
				if ( ! str_starts_with( $key, $prefix ) || '' === $value ) {
					continue;
				}

				$parts      = explode( '|', $value, 2 );
				$expires_at = NumericIdentifier::positive( $parts[0] ?? null ) ?? 0;
				if ( $expires_at <= $now && OptionMutex::release( $key, $value ) ) {
					++$deleted;
				}
			}
		}

		if ( $has_more && false === wp_next_scheduled( self::CLEANUP_BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CLEANUP_BATCH_HOOK );
		}

		return $deleted;
	}

	public function can_receive( WP_REST_Request $request ): bool|WP_Error {
		$expected = $this->options->existing_webhook_secret();
		$provided = $this->provided_token( $request );

		if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'soocool_webhook_forbidden', __( 'Ongeldige SooCool webhook-token.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$signature_result = $this->verify_signature( $request, $expected );
		if ( is_wp_error( $signature_result ) ) {
			return $signature_result;
		}

		$replay_result = $this->reject_replay( $request );
		if ( is_wp_error( $replay_result ) ) {
			return $replay_result;
		}

		return $this->reserve_delivery( $request );
	}

	public function event_id( WP_REST_Request $request ): string {
		return $this->provided_event_id( $request );
	}

	public function delivery_timestamp( WP_REST_Request $request ): int {
		return $this->provided_timestamp( $request );
	}

	public function mark_processed( WP_REST_Request $request ): void {
		$replay_key = $this->replay_key( $request );
		if ( '' !== $replay_key ) {
			$processed_key = $this->processed_key( $replay_key );
			$stored        = OptionMutex::acquire( $processed_key, self::PROCESSED_TTL_SECONDS );
			if ( null === $stored && ! $this->processed_delivery_exists( $processed_key ) ) {
				// Keep the short reservation until expiry when durable replay storage fails.
				unset( $this->reservations[ $request ] );
				return;
			}
			set_transient( $replay_key, '1', self::SIGNATURE_TOLERANCE_SECONDS * 2 );
		}

		$this->release_reservation( $request );
	}

	public function refresh_reservation( WP_REST_Request $request ): bool {
		$reservation = $this->reservations[ $request ] ?? null;
		if ( ! is_array( $reservation ) ) {
			return '' === $this->replay_key( $request );
		}

		$refreshed = OptionMutex::refresh( $reservation['key'], $reservation['value'], self::RESERVATION_TTL_SECONDS );
		if ( null === $refreshed ) {
			return false;
		}

		$reservation['value'] = $refreshed;
		$this->reservations[ $request ] = $reservation;
		return true;
	}

	public function release_reservation( WP_REST_Request $request ): void {
		$reservation = $this->reservations[ $request ] ?? null;
		unset( $this->reservations[ $request ] );

		if ( is_array( $reservation ) ) {
			OptionMutex::release( $reservation['key'], $reservation['value'] );
		}
	}

	private function reserve_delivery( WP_REST_Request $request ): bool|WP_Error {
		if ( isset( $this->reservations[ $request ] ) ) {
			return true;
		}

		$replay_key = $this->replay_key( $request );
		if ( '' === $replay_key ) {
			return true;
		}

		$key   = self::RESERVATION_PREFIX . md5( $replay_key );
		$value = OptionMutex::acquire( $key, self::RESERVATION_TTL_SECONDS );
		if ( null === $value ) {
			return new WP_Error( 'soocool_webhook_in_progress', __( 'Deze SooCool webhook-delivery wordt al verwerkt.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}

		$this->reservations[ $request ] = array(
			'key'   => $key,
			'value' => $value,
		);
		return true;
	}

	private function verify_signature( WP_REST_Request $request, string $secret ): bool|WP_Error {
		$timestamp = $this->provided_timestamp( $request );
		$signature = $this->provided_signature( $request );

		if ( 0 >= $timestamp || '' === $signature ) {
			return new WP_Error( 'soocool_webhook_signature_missing', __( 'SooCool webhook-signature headers ontbreken.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		if ( abs( time() - $timestamp ) > self::SIGNATURE_TOLERANCE_SECONDS ) {
			return new WP_Error( 'soocool_webhook_timestamp_expired', __( 'SooCool webhook-timestamp is verlopen.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		$body = $request->get_body();
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_SIGNED_BODY_BYTES ) {
			return new WP_Error( 'soocool_webhook_payload_too_large', __( 'SooCool webhook-payload is te groot.', 'soocool-for-woocommerce' ), array( 'status' => 413 ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		if ( ! hash_equals( $expected, strtolower( $signature ) ) ) {
			return new WP_Error( 'soocool_webhook_signature_invalid', __( 'Ongeldige SooCool webhook-signature.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		return true;
	}

	private function reject_replay( WP_REST_Request $request ): bool|WP_Error {
		$replay_key = $this->replay_key( $request );

		if (
			'' !== $replay_key
			&& (
				false !== get_transient( $replay_key )
				|| $this->processed_delivery_exists( $this->processed_key( $replay_key ) )
			)
		) {
			return new WP_Error( 'soocool_webhook_replay', __( 'Dubbele SooCool webhook-delivery.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}

		return true;
	}

	private function processed_key( string $replay_key ): string {
		return self::PROCESSED_PREFIX . md5( $replay_key );
	}

	private function processed_delivery_exists( string $key ): bool {
		$stored = get_option( $key, null );
		if ( ! is_scalar( $stored ) ) {
			return false;
		}

		$value      = (string) $stored;
		$parts      = explode( '|', $value, 2 );
		$expires_at = NumericIdentifier::positive( $parts[0] ?? null ) ?? 0;
		if ( $expires_at > time() ) {
			return true;
		}

		OptionMutex::release( $key, $value );
		return false;
	}

	private function replay_key( WP_REST_Request $request ): string {
		$signature = $this->provided_signature( $request );
		$event_id  = $this->provided_event_id( $request );
		$timestamp = $this->provided_timestamp( $request );

		if ( '' !== $event_id ) {
			$replay_id = 'event:' . $event_id;
		} elseif ( 0 < $timestamp && '' !== $signature ) {
			$replay_id = 'signature:' . $timestamp . ':' . $signature;
		} else {
			$body = $request->get_body();
			if ( ! is_string( $body ) || '' === $body || strlen( $body ) > self::MAX_SIGNED_BODY_BYTES ) {
				return '';
			}

			$replay_id = 'body:' . hash( 'sha256', $body );
		}

		return 'soocool_webhook_replay_' . md5( $replay_id );
	}

	private function provided_token( WP_REST_Request $request ): string {
		$token = $request->get_header( 'x-soocool-webhook-token' );
		if ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) {
			$token = $request->get_header( 'x_webhook_token' );
		}
		return is_scalar( $token ) ? trim( sanitize_text_field( (string) $token ) ) : '';
	}

	private function provided_signature( WP_REST_Request $request ): string {
		$signature = $request->get_header( self::SIGNATURE_HEADER );
		if ( ! is_scalar( $signature ) || '' === trim( (string) $signature ) ) {
			$signature = $request->get_header( 'x_soocool_webhook_signature' );
		}

		$signature = is_scalar( $signature ) ? trim( sanitize_text_field( (string) $signature ) ) : '';
		if ( str_starts_with( strtolower( $signature ), 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		return 1 === preg_match( '/^[a-f0-9]{64}$/i', $signature ) ? strtolower( $signature ) : '';
	}

	private function provided_timestamp( WP_REST_Request $request ): int {
		$timestamp = $request->get_header( self::TIMESTAMP_HEADER );
		if ( ! is_scalar( $timestamp ) || '' === trim( (string) $timestamp ) ) {
			$timestamp = $request->get_header( 'x_soocool_webhook_timestamp' );
		}

		$timestamp = is_scalar( $timestamp ) ? trim( (string) $timestamp ) : '';
		return NumericIdentifier::positive( $timestamp ) ?? 0;
	}

	private function provided_event_id( WP_REST_Request $request ): string {
		$event_id = $request->get_header( self::EVENT_ID_HEADER );
		if ( ! is_scalar( $event_id ) || '' === trim( (string) $event_id ) ) {
			$event_id = $request->get_header( 'x_soocool_webhook_id' );
		}

		$event_id = is_scalar( $event_id ) ? trim( sanitize_text_field( (string) $event_id ) ) : '';
		return 1 === preg_match( '/^[A-Za-z0-9_.:-]{1,128}$/', $event_id ) ? $event_id : '';
	}
}
