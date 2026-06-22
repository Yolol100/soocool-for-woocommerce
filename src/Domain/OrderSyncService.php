<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\WooCommerce\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Shared SooCool sync primitives used by both the REST sync endpoint and the
 * WooCommerce order actions: a per-order lock that prevents concurrent
 * submissions and a lookup that links to an existing SooCool order instead of
 * creating a duplicate. Previously duplicated in OrderActions and
 * OrderSyncController.
 */
final class OrderSyncService {

	private const SYNC_LOCK_TTL_SECONDS = 120;

	public function __construct( private readonly ApiClient $client, private readonly OrderMeta $meta ) {}

	/**
	 * Acquire a per-order lock. Relies on the unique option_name index so the
	 * winning add_option() is atomic even if two requests pass the TTL check.
	 */
	public function acquire_lock( int $order_id ): bool {
		$key     = $this->lock_key( $order_id );
		$expires = (int) get_option( $key, 0 );
		$now     = time();

		if ( $expires > $now ) {
			return false;
		}

		if ( $expires > 0 ) {
			delete_option( $key );
		}

		return add_option( $key, (string) ( $now + self::SYNC_LOCK_TTL_SECONDS ), '', false );
	}

	public function release_lock( int $order_id ): void {
		delete_option( $this->lock_key( $order_id ) );
	}

	private function lock_key( int $order_id ): string {
		return 'soocool_sync_lock_' . absint( $order_id );
	}

	/**
	 * Look up an existing SooCool order by reference.
	 *
	 * @return array<string, mixed> Empty array when no matching order exists.
	 */
	public function find_existing_order( string $order_reference ): array {
		try {
			$response = $this->client->search_order_by_reference( $order_reference );
		} catch ( ApiException $exception ) {
			// A 404 on the search endpoint means no order with this reference exists yet.
			if ( 404 === $exception->status_code() ) {
				return array();
			}
			throw $exception;
		}

		$body = $response->body();
		if ( ! is_array( $body ) ) {
			return array();
		}

		$candidate = $body;
		if ( array_is_list( $body ) ) {
			$candidate = is_array( $body[0] ?? null ) ? $body[0] : array();
		}

		if ( ! is_array( $candidate ) || '' === $this->meta->extract_order_id( $candidate ) ) {
			return array();
		}

		return $candidate;
	}
}
