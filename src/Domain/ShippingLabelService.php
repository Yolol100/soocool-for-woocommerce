<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Infrastructure\ConnectionStateRepository;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\ProviderContext;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class ShippingLabelService {

	private readonly RemoteOrderResponseParser $responses;
	private readonly ProviderContext $provider_context;

	public function __construct( private readonly ApiClient $client, private readonly OrderMeta $meta, private readonly OptionRepository $options, ?RemoteOrderResponseParser $responses = null, ?ProviderContext $provider_context = null ) {
		$this->responses        = $responses ?? new RemoteOrderResponseParser( $meta );
		$this->provider_context = $provider_context ?? new ProviderContext( $options, new ConnectionStateRepository() );
	}

	/** @deprecated Use download_label() to avoid loading PDFs into memory. */
	public function get_label( WC_Order $order, string $output ): string {
		return $this->read_and_delete( $this->download_label( $order, $output ) );
	}

	/** @deprecated Use download_good_label() to avoid loading PDFs into memory. */
	public function get_good_label( WC_Order $order, int|string $good_id, string $output ): string {
		return $this->read_and_delete( $this->download_good_label( $order, $good_id, $output ) );
	}

	/** @param array<int, int|string> $good_ids @deprecated Bulk good labels require WooCommerce order context; use download_bulk_good_labels_for_orders(). */
	public function get_bulk_good_labels( array $good_ids, string $output ): string {
		return $this->read_and_delete( $this->download_bulk_good_labels( $good_ids, $output ) );
	}

	/** @param array<int, WC_Order> $orders @deprecated Use download_bulk_labels(). */
	public function get_bulk_labels( array $orders, string $output ): string {
		return $this->read_and_delete( $this->download_bulk_labels( $orders, $output ) );
	}

	public function download_label( WC_Order $order, string $output ): string {
		$soocool_order_id = $this->resolved_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			throw new \RuntimeException( __( 'Deze order heeft nog geen geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
		}
		return $this->client->download_shipping_label( $soocool_order_id, $output );
	}

	public function download_good_label( WC_Order $order, int|string $good_id, string $output ): string {
		$soocool_order_id = $this->resolved_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			throw new \RuntimeException( __( 'Deze order heeft nog geen geldig numeriek SooCool order-ID.', 'soocool-for-woocommerce' ) );
		}
		return $this->client->download_good_shipping_label( $soocool_order_id, $good_id, $output );
	}

	/**
	 * @param array<int, int|string> $good_ids
	 * @deprecated Prefer download_bulk_good_labels_for_orders() so WooCommerce order/provider context can be verified.
	 */
	public function download_bulk_good_labels( array $good_ids, string $output ): string {
		return $this->client->download_multiple_good_shipping_labels( $good_ids, $output );
	}

	/**
	 * @param array<int, WC_Order>   $orders
	 * @param array<int, int|string> $good_ids
	 */
	public function download_bulk_good_labels_for_orders( array $orders, array $good_ids, string $output ): string {
		$requested_good_ids = array();
		foreach ( $good_ids as $good_id ) {
			$normalized_good_id = \SooCool\WooCommerce\Infrastructure\NumericIdentifier::positive( $good_id );
			if ( null === $normalized_good_id ) {
				throw new \RuntimeException( __( 'Een SooCool-goederen-ID voor de bulkdownload is ongeldig.', 'soocool-for-woocommerce' ) );
			}
			$requested_good_ids[] = $normalized_good_id;
		}
		$requested_good_ids = array_values( array_unique( $requested_good_ids ) );
		if ( array() === $requested_good_ids || array() === $orders ) {
			throw new \RuntimeException( __( 'Geen geldige orders of SooCool-goederen-ID’s opgegeven voor de bulkdownload.', 'soocool-for-woocommerce' ) );
		}

		$current_provider_good_ids = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || '' === $this->resolved_soocool_order_id( $order ) ) {
				throw new \RuntimeException( __( 'Eén of meer geselecteerde orders zijn niet teruggevonden in de actieve SooCool API-context.', 'soocool-for-woocommerce' ) );
			}

			$order_good_ids = $this->meta->get_good_ids( $order );
			if ( array() === $order_good_ids ) {
				throw new \RuntimeException( __( 'De SooCool-goederen-ID’s van één of meer orders konden niet in de actieve API-context worden bevestigd.', 'soocool-for-woocommerce' ) );
			}
			foreach ( $order_good_ids as $good_id ) {
				$current_provider_good_ids[] = $good_id;
			}
		}
		$current_provider_good_ids = array_values( array_unique( $current_provider_good_ids ) );
		if ( array() !== array_diff( $requested_good_ids, $current_provider_good_ids ) ) {
			throw new \RuntimeException( __( 'Eén of meer aangevraagde SooCool-goederen-ID’s horen niet bij de geselecteerde orders in de actieve API-context.', 'soocool-for-woocommerce' ) );
		}

		return $this->client->download_multiple_good_shipping_labels( $requested_good_ids, $output );
	}

	/** @param array<int, WC_Order> $orders */
	public function download_bulk_labels( array $orders, string $output ): string {
		$soocool_order_ids = array();
		$unresolved        = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				++$unresolved;
				continue;
			}
			$soocool_order_id = $this->resolved_soocool_order_id( $order );
			if ( '' !== $soocool_order_id ) {
				$soocool_order_ids[] = $soocool_order_id;
			} else {
				++$unresolved;
			}
		}
		$soocool_order_ids = array_values( array_unique( $soocool_order_ids ) );
		if ( array() === $soocool_order_ids || 0 < $unresolved ) {
			throw new \RuntimeException( __( 'Eén of meer geselecteerde orders zijn niet teruggevonden bij SooCool.', 'soocool-for-woocommerce' ) );
		}
		return 1 === count( $soocool_order_ids )
			? $this->client->download_shipping_label( $soocool_order_ids[0], $output )
			: $this->client->download_multiple_shipping_labels( $soocool_order_ids, $output );
	}

	private function read_and_delete( string $filename ): string {
		try {
			$size = is_file( $filename ) ? filesize( $filename ) : false;
			if ( false === $size || $size > 26214400 ) {
				throw new \RuntimeException( __( 'Het SooCool-label is te groot of ontbreekt.', 'soocool-for-woocommerce' ) );
			}
			$pdf = file_get_contents( $filename );
			if ( false === $pdf ) {
				throw new \RuntimeException( __( 'Het SooCool-label kon niet worden gelezen.', 'soocool-for-woocommerce' ) );
			}
			return $pdf;
		} finally {
			wp_delete_file( $filename );
		}
	}

	private function resolved_soocool_order_id( WC_Order $order ): string {
		$stored_order_id       = $this->meta->get_soocool_order_id( $order );
		$stored_provider_context = $this->meta->get_provider_context( $order );
		if ( '' !== $stored_order_id && '' !== $stored_provider_context && $this->provider_context->matches_provider( $stored_provider_context ) ) {
			$remote_order = $this->remote_order_by_id( $stored_order_id );
			if ( array() !== $remote_order ) {
				return $this->remember_remote_order( $order, $remote_order, $this->responses->reference( $remote_order ) );
			}
		}

		$remote_order = $this->remote_order_by_reference( $order );
		if ( array() !== $remote_order ) {
			return $this->remember_remote_order( $order, $remote_order, $this->responses->reference( $remote_order ) );
		}

		return '';
	}

	/** @return array<string, mixed> */
	private function remote_order_by_reference( WC_Order $order ): array {
		$matches = array();
		foreach ( $this->order_reference_candidates( $order ) as $reference ) {
			try {
				$response = $this->client->search_order_by_reference( $reference );
			} catch ( ApiException $exception ) {
				if ( 404 === $exception->status_code() ) {
					continue;
				}
				throw $exception;
			}

			$remote_order = $this->first_order_from_search_response( $response->body(), $reference );
			if ( array() === $remote_order ) {
				continue;
			}

			$remote_order_id = $this->meta->extract_order_id( $remote_order );
			if ( '' === $remote_order_id ) {
				throw new ApiException( __( 'SooCool gaf een zoekresultaat zonder geldige order-ID terug.', 'soocool-for-woocommerce' ), 502 );
			}
			$matches[ $remote_order_id ] = $remote_order;
		}

		if ( 1 < count( $matches ) ) {
			throw new ApiException( __( 'Meerdere SooCool-orders passen bij deze WooCommerce-order in de actieve API-context. De labeldownload is gestopt om een verkeerde koppeling te voorkomen.', 'soocool-for-woocommerce' ), 409 );
		}

		if ( 1 === count( $matches ) ) {
			$remote_order = reset( $matches );
			return is_array( $remote_order ) ? $remote_order : array();
		}

		return array();
	}

	/** @return array<string, mixed> */
	private function remote_order_by_id( string $soocool_order_id ): array {
		try {
			$response = $this->client->get_order( $soocool_order_id );
		} catch ( ApiException $exception ) {
			if ( 404 === $exception->status_code() ) {
				return array();
			}
			throw $exception;
		}

		$body = $response->body();
		if ( ! is_array( $body ) ) {
			throw new ApiException( __( 'SooCool gaf een ongeldige orderresponse terug.', 'soocool-for-woocommerce' ), 502 );
		}

		$matches = array();
		foreach ( $this->responses->candidates( $body ) as $remote_order ) {
			if ( $this->meta->extract_order_id( $remote_order ) === $soocool_order_id ) {
				$matches[ $this->responses->fingerprint( $remote_order ) ] = $remote_order;
			}
		}

		if ( 1 < count( $matches ) ) {
			throw new ApiException( __( 'SooCool gaf meerdere orders met hetzelfde order-ID terug.', 'soocool-for-woocommerce' ), 502 );
		}

		if ( 1 === count( $matches ) ) {
			$order = reset( $matches );
			return is_array( $order ) ? $order : array();
		}

		return array();
	}

	/** @return array<int, string> */
	private function order_reference_candidates( WC_Order $order ): array {
		$order_number = trim( sanitize_text_field( (string) $order->get_order_number() ) );
		$settings     = $this->options->all();
		$prefix       = sanitize_key( (string) ( $settings['order_reference_prefix'] ?? '' ) );
		$prefixed     = '' !== $prefix && '' !== $order_number ? $prefix . '-' . $order_number : '';

		$candidates = array(
			$this->meta->get_order_reference( $order ),
			$this->meta->get_our_reference( $order ),
			$prefixed,
			$order_number,
		);

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( string $candidate ): string => trim( sanitize_text_field( $candidate ) ),
						$candidates
					)
				)
			)
		);
	}

	/**
	 * @param mixed $body
	 * @return array<string, mixed>
	 */
	private function first_order_from_search_response( mixed $body, string $reference ): array {
		if ( ! is_array( $body ) ) {
			throw new ApiException( __( 'SooCool gaf een ongeldige zoekresponse terug.', 'soocool-for-woocommerce' ), 502 );
		}

		$matches = array();
		foreach ( $this->responses->candidates( $body ) as $remote_order ) {
			if ( $this->responses->reference( $remote_order ) === $reference ) {
				$matches[ $this->responses->fingerprint( $remote_order ) ] = $remote_order;
			}
		}

		if ( 1 < count( $matches ) ) {
			throw new ApiException( __( 'SooCool gaf meerdere orders met dezelfde orderreferentie terug.', 'soocool-for-woocommerce' ), 502 );
		}

		if ( 1 === count( $matches ) ) {
			$order = reset( $matches );
			return is_array( $order ) ? $order : array();
		}

		return array();
	}

	/** @param array<string, mixed> $remote_order */
	private function remember_remote_order( WC_Order $order, array $remote_order, string $order_reference = '' ): string {
		$soocool_order_id = $this->meta->extract_order_id( $remote_order );
		if ( '' === $soocool_order_id ) {
			return '';
		}

		if ( '' === $order_reference ) {
			$order_reference = $this->meta->get_order_reference( $order );
		}

		try {
			$this->meta->save_success( $order, $remote_order, $order_reference, true, $this->provider_context->provider_fingerprint() );
		} catch ( \Throwable ) {
			return $soocool_order_id;
		}

		return $soocool_order_id;
	}
}
