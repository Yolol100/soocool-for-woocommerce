<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Infrastructure\OptionMutex;
use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\WooCommerce\OrderMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Provides per-order synchronization locking and existing-order lookup.
 */
final class OrderSyncService {

	private const SYNC_LOCK_TTL_SECONDS = 300;

	/** @var array<int, string> */
	private array $lock_tokens = array();

	private readonly RemoteOrderResponseParser $responses;

	public function __construct( private readonly ApiClient $client, private readonly OrderMeta $meta, ?RemoteOrderResponseParser $responses = null ) {
		$this->responses = $responses ?? new RemoteOrderResponseParser( $meta );
	}

	public function acquire_lock( int $order_id ): bool {
		$value = OptionMutex::acquire( $this->lock_key( $order_id ), self::SYNC_LOCK_TTL_SECONDS );
		if ( null === $value ) {
			return false;
		}

		$this->lock_tokens[ $order_id ] = $value;
		return true;
	}

	public function refresh_lock( int $order_id ): bool {
		$value = $this->lock_tokens[ $order_id ] ?? '';
		if ( '' === $value ) {
			return false;
		}

		$refreshed = OptionMutex::refresh( $this->lock_key( $order_id ), $value, self::SYNC_LOCK_TTL_SECONDS );
		if ( null === $refreshed ) {
			return false;
		}

		$this->lock_tokens[ $order_id ] = $refreshed;
		return true;
	}

	public function release_lock( int $order_id ): void {
		$value = $this->lock_tokens[ $order_id ] ?? '';
		unset( $this->lock_tokens[ $order_id ] );
		if ( '' !== $value ) {
			OptionMutex::release( $this->lock_key( $order_id ), $value );
		}
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
			throw new ApiException( __( 'SooCool gaf een ongeldige zoekresponse terug.', 'soocool-for-woocommerce' ), 502 );
		}

		$candidates = $this->responses->candidates( $body, true );
		$matches           = array();
		$invalid_match     = false;
		$matched_order_ids = array();

		foreach ( $candidates as $candidate ) {
			if ( $this->responses->reference( $candidate ) !== $order_reference ) {
				continue;
			}

			$order_id = $this->meta->extract_order_id( $candidate );
			if ( '' === $order_id ) {
				$invalid_match = true;
				continue;
			}

			$matches[]                    = $candidate;
			$matched_order_ids[ $order_id ] = true;
		}

		if ( $invalid_match ) {
			throw new ApiException( __( 'SooCool gaf een zoekresultaat zonder geldige order-ID terug.', 'soocool-for-woocommerce' ), 502 );
		}
		if ( count( $matched_order_ids ) > 1 ) {
			throw new ApiException( __( 'SooCool gaf meerdere orders met dezelfde orderreferentie terug. Synchronisatie is gestopt om een verkeerde koppeling te voorkomen.', 'soocool-for-woocommerce' ), 409 );
		}

		return $matches[0] ?? array();
	}

}
