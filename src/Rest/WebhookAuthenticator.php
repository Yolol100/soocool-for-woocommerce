<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WebhookAuthenticator {

	private const SIGNATURE_HEADER = 'x-soocool-webhook-signature';
	private const TIMESTAMP_HEADER = 'x-soocool-webhook-timestamp';
	private const EVENT_ID_HEADER  = 'x-soocool-webhook-id';
	private const SIGNATURE_TOLERANCE_SECONDS = 300;
	private const MAX_SIGNED_BODY_BYTES       = 262144;

	public function __construct( private readonly OptionRepository $options ) {}

	public function can_receive( WP_REST_Request $request ): bool|WP_Error {
		$expected = $this->options->existing_webhook_secret();
		$provided = $this->provided_token( $request );

		if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'soocool_webhook_forbidden', __( 'Ongeldige SooCool webhook-token.', 'soocool-for-woocommerce' ), array( 'status' => 403 ) );
		}

		if ( ! $this->signature_required() && ! $this->has_signature_headers( $request ) ) {
			return true;
		}

		$signature_result = $this->verify_signature( $request, $expected );
		if ( is_wp_error( $signature_result ) ) {
			return $signature_result;
		}

		$replay_result = $this->reject_replay( $request );
		if ( is_wp_error( $replay_result ) ) {
			return $replay_result;
		}

		return true;
	}

	private function signature_required(): bool {
		/**
		 * Require HMAC verification for incoming SooCool webhooks.
		 *
		 * Keep enabled unless the remote SooCool callback configuration cannot send
		 * signature headers yet. Token-only fallback requires the
		 * SOOCOOL_ALLOW_INSECURE_WEBHOOK_FALLBACK constant and a false filter value.
		 *
		 * @param bool $required Default true.
		 */
		$required = (bool) apply_filters( 'soocool_require_webhook_signature', true );
		if ( $required ) {
			return true;
		}

		return ! ( defined( 'SOOCOOL_ALLOW_INSECURE_WEBHOOK_FALLBACK' ) && (bool) SOOCOOL_ALLOW_INSECURE_WEBHOOK_FALLBACK );
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
		$signature = $this->provided_signature( $request );
		$event_id  = $this->provided_event_id( $request );
		$timestamp = $this->provided_timestamp( $request );

		$replay_id = '' !== $event_id ? $event_id : $timestamp . ':' . $signature;
		$key       = 'soocool_webhook_replay_' . md5( $replay_id );

		if ( false !== get_transient( $key ) ) {
			return new WP_Error( 'soocool_webhook_replay', __( 'Dubbele SooCool webhook-delivery.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}

		set_transient( $key, '1', self::SIGNATURE_TOLERANCE_SECONDS * 2 );
		return true;
	}

	private function has_signature_headers( WP_REST_Request $request ): bool {
		return 0 < $this->provided_timestamp( $request ) || '' !== $this->provided_signature( $request );
	}

	private function provided_token( WP_REST_Request $request ): string {
		$token = $request->get_header( 'x-soocool-webhook-token' );
		if ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) {
			$token = $request->get_header( 'x_webhook_token' );
		}
		if ( ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) && $this->options->query_token_fallback_enabled() && $request->has_param( 'token' ) ) {
			$token = $request->get_param( 'token' );
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
		return ctype_digit( $timestamp ) ? (int) $timestamp : 0;
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
