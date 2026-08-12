<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\Infrastructure\OptionMutex;
use SooCool\WooCommerce\Infrastructure\ProviderContext;
defined( 'ABSPATH' ) || exit;

final class ShippingLabelBulkTokenStore {

	private const PREFIX      = 'soocool_bulk_label_';
	private const LOCK_PREFIX = 'soocool_bulk_label_lock_';
	private const TTL         = 300;
	private const LOCK_TTL    = 60;
	private const MAX_ORDER_IDS = 50;

	public function __construct( private readonly ?ProviderContext $provider_context = null ) {}

	/** @param array<int, int> $order_ids */
	public function create( string $action, array $order_ids, string $output ): string {
		$action = sanitize_key( $action );
		$order_ids = NumericIdentifier::positive_list( $order_ids );
		if ( ! in_array( $action, array( 'soocool_download_order_labels', 'soocool_download_good_labels' ), true ) || array() === $order_ids || count( $order_ids ) > self::MAX_ORDER_IDS ) {
			return '';
		}

		$user_id = NumericIdentifier::positive( get_current_user_id() );
		if ( null === $user_id ) {
			return '';
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		$token = sanitize_key( str_replace( '-', '', $token ) );
		if ( '' === $token ) {
			return '';
		}

		$payload = array(
			'user_id'             => $user_id,
			'context_fingerprint' => $this->current_context_fingerprint(),
			'action'    => $action,
			'order_ids' => $order_ids,
			'output'    => 'collated_a4' === $output ? 'collated_a4' : 'a6',
			'created'   => time(),
		);

		return set_transient( $this->transient_key( $token ), $payload, self::TTL ) ? $token : '';
	}

	/** @return array<string, mixed> */
	public function consume( string $token ): array {
		$lock_key   = $this->lock_key( $token );
		$lock_value = OptionMutex::acquire( $lock_key, self::LOCK_TTL );
		if ( null === $lock_value ) {
			return array();
		}

		try {
			$transient_key = $this->transient_key( $token );
			$payload       = get_transient( $transient_key );
			if ( ! is_array( $payload ) ) {
				delete_transient( $transient_key );
				return array();
			}

			$owner_id = NumericIdentifier::positive( $payload['user_id'] ?? null );
			if ( null === $owner_id ) {
				delete_transient( $transient_key );
				return array();
			}

			$current_id = NumericIdentifier::positive( get_current_user_id() );
			if ( null === $current_id || $owner_id !== $current_id ) {
				return array();
			}

			$context_fingerprint = isset( $payload['context_fingerprint'] ) && is_scalar( $payload['context_fingerprint'] )
				? strtolower( trim( (string) $payload['context_fingerprint'] ) )
				: '';
			if ( null !== $this->provider_context && ( '' === $context_fingerprint || ! $this->context_is_current( $context_fingerprint ) ) ) {
				delete_transient( $transient_key );
				return array();
			}

			delete_transient( $transient_key );
			return $payload;
		} finally {
			OptionMutex::release( $lock_key, $lock_value );
		}
	}


	private function current_context_fingerprint(): string {
		return null !== $this->provider_context ? $this->provider_context->execution_fingerprint( 'bulk-label' ) : '';
	}

	private function context_is_current( string $context_fingerprint ): bool {
		return null !== $this->provider_context && $this->provider_context->matches_execution( $context_fingerprint, 'bulk-label' );
	}

	public function nonce_action( string $token ): string {
		return 'soocool_download_bulk_labels_' . md5( $token );
	}

	private function transient_key( string $token ): string {
		return self::PREFIX . md5( $token );
	}

	private function lock_key( string $token ): string {
		return self::LOCK_PREFIX . md5( $token );
	}
}
