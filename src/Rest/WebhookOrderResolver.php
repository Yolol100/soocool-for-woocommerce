<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\Infrastructure\NumericIdentifier;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class WebhookOrderResolver {

	public function __construct(
		private readonly OrderMeta $meta,
		private readonly Logger $logger,
		private readonly WebhookPayloadExtractor $payloads,
		private readonly ApiClient $client
	) {}

	public function find_order( string $soocool_order_id, string $order_reference, int $wc_order_id ): array|WP_Error|null {
		$candidates = array();

		if ( '' !== $soocool_order_id ) {
			$error = $this->add_order_candidate( $candidates, $this->find_order_by_meta( OrderMeta::ORDER_ID, $soocool_order_id ) );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}
		if ( '' !== $order_reference ) {
			$error = $this->add_order_candidate( $candidates, $this->find_order_by_reference( $order_reference ) );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}
		if ( 0 < $wc_order_id ) {
			$error = $this->add_order_candidate( $candidates, $this->find_order_by_wc_order_id( $wc_order_id ) );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		if ( count( $candidates ) > 1 ) {
			return $this->identifier_conflict( $soocool_order_id, $order_reference, $wc_order_id );
		}

		$order = reset( $candidates );
		if ( $order instanceof WC_Order ) {
			if ( 0 < $wc_order_id && (int) $order->get_id() !== $wc_order_id ) {
				return $this->identifier_conflict( $soocool_order_id, $order_reference, $wc_order_id );
			}
			if ( '' !== $order_reference && ! $this->order_matches_reference( $order, $order_reference ) ) {
				return $this->identifier_conflict( $soocool_order_id, $order_reference, $wc_order_id );
			}
			$current_remote_id = $this->meta->get_soocool_order_id( $order );
			if ( '' !== $soocool_order_id && '' !== $current_remote_id && $current_remote_id !== $soocool_order_id ) {
				return $this->identifier_conflict( $soocool_order_id, $order_reference, $wc_order_id );
			}

			return array(
				'order'        => $order,
				'remote_order' => array(),
				'reference'    => '' !== $order_reference ? $order_reference : ( $this->meta->get_order_reference( $order ) ?: $this->meta->get_our_reference( $order ) ),
			);
		}

		$remote_order = '' !== $soocool_order_id ? $this->remote_order( $soocool_order_id ) : array();
		if ( is_wp_error( $remote_order ) ) {
			return $remote_order;
		}
		if ( array() !== $remote_order ) {
			$remote_reference = $this->payloads->order_reference( $remote_order );
			if ( '' !== $remote_reference ) {
				if ( '' !== $order_reference && ! hash_equals( $order_reference, $remote_reference ) ) {
					return $this->identifier_conflict( $soocool_order_id, $order_reference, $wc_order_id );
				}

				$order = $this->find_order_by_reference( $remote_reference );
				if ( $order instanceof WC_Order ) {
					if ( 0 < $wc_order_id && (int) $order->get_id() !== $wc_order_id ) {
						return $this->identifier_conflict( $soocool_order_id, $order_reference, $wc_order_id );
					}
					if ( ! $this->order_matches_reference( $order, $remote_reference ) ) {
						return $this->identifier_conflict( $soocool_order_id, $remote_reference, $wc_order_id );
					}

					$current_remote_id = $this->meta->get_soocool_order_id( $order );
					if ( '' !== $current_remote_id && $current_remote_id !== $soocool_order_id ) {
						return $this->identifier_conflict( $soocool_order_id, $remote_reference, $wc_order_id );
					}

					return array(
						'order'        => $order,
						'remote_order' => $remote_order,
						'reference'    => $remote_reference,
					);
				}
			}
		}

		return null;
	}

	private function add_order_candidate( array &$candidates, WC_Order|WP_Error|null $order ): ?WP_Error {
		if ( $order instanceof WP_Error ) {
			return $order;
		}
		if ( $order instanceof WC_Order ) {
			$candidates[ (int) $order->get_id() ] = $order;
		}

		return null;
	}

	private function order_matches_reference( WC_Order $order, string $reference ): bool {
		$reference = trim( sanitize_text_field( $reference ) );
		if ( '' === $reference ) {
			return true;
		}

		$known = array(
			$this->meta->get_order_reference( $order ),
			$this->meta->get_our_reference( $order ),
			sanitize_text_field( (string) $order->get_order_number() ),
			(string) $order->get_id(),
		);
		foreach ( $known as $candidate ) {
			if ( '' !== $candidate && hash_equals( $candidate, $reference ) ) {
				return true;
			}
		}

		return 1 === preg_match( '/(?:^|-)(' . preg_quote( (string) $order->get_order_number(), '/' ) . ')$/', $reference );
	}

	public function identifier_conflict( string $soocool_order_id, string $order_reference, int $wc_order_id ): WP_Error {
		$this->logger->info(
			'SooCool webhook geweigerd: orderidentificaties verwijzen niet naar dezelfde WooCommerce-order.',
			array(
				'orderId'        => '' !== $soocool_order_id ? $soocool_order_id : '[missing]',
				'orderReference' => '' !== $order_reference ? $order_reference : '[missing]',
				'wcOrderId'      => 0 < $wc_order_id ? (string) $wc_order_id : '[missing]',
			)
		);

		return new WP_Error(
			'soocool_webhook_identifier_conflict',
			__( 'SooCool webhook-orderidentificaties komen niet overeen.', 'soocool-for-woocommerce' ),
			array( 'status' => 409 )
		);
	}

	private function find_order_by_reference( string $order_reference ): WC_Order|WP_Error|null {
		$candidates = array();
		$resolved   = apply_filters( 'soocool_resolve_order_by_reference', null, $order_reference );
		if ( $resolved instanceof WP_Error ) {
			return $resolved;
		}
		if ( is_numeric( $resolved ) ) {
			$resolved = wc_get_order( (int) $resolved );
		}
		if ( null !== $resolved && ! $resolved instanceof WC_Order ) {
			return new WP_Error(
				'soocool_webhook_invalid_order_resolver',
				__( 'De orderreferentieresolver gaf een ongeldig resultaat terug.', 'soocool-for-woocommerce' ),
				array( 'status' => 500 )
			);
		}
		if ( $resolved instanceof WC_Order ) {
			$candidates[ (int) $resolved->get_id() ] = $resolved;
		}
		foreach ( array( OrderMeta::ORDER_REFERENCE, OrderMeta::OUR_REFERENCE ) as $meta_key ) {
			$order = $this->find_order_by_meta( $meta_key, $order_reference );
			if ( $order instanceof WP_Error ) {
				return $order;
			}
			if ( $order instanceof WC_Order ) {
				$candidates[ (int) $order->get_id() ] = $order;
			}
		}

		$order = $this->find_order_by_order_number( $order_reference );
		if ( $order instanceof WC_Order ) {
			$candidates[ (int) $order->get_id() ] = $order;
		}
		if ( 1 < count( $candidates ) ) {
			return new WP_Error(
				'soocool_webhook_ambiguous_order',
				__( 'Meerdere WooCommerce-orders passen bij dezelfde SooCool-orderreferentie.', 'soocool-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		$order = reset( $candidates );
		return $order instanceof WC_Order ? $order : null;
	}

	private function find_order_by_meta( string $meta_key, string $meta_value ): WC_Order|WP_Error|null {
		$orders = wc_get_orders(
			array(
				'limit'      => 2,
				'return'     => 'objects',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exact lookup for plugin-owned SooCool identifiers.
					array(
						'key'     => $meta_key,
						'value'   => $meta_value,
						'compare' => '=',
					),
				),
			)
		);

		$orders = is_array( $orders ) ? array_values( array_filter( $orders, static fn ( mixed $order ): bool => $order instanceof WC_Order ) ) : array();
		if ( 1 < count( $orders ) ) {
			return new WP_Error(
				'soocool_webhook_ambiguous_order',
				__( 'Meerdere WooCommerce-orders hebben dezelfde SooCool-identificatie.', 'soocool-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		return 1 === count( $orders ) ? $orders[0] : null;
	}

	private function find_order_by_order_number( string $order_reference ): ?WC_Order {
		$candidates = array( trim( sanitize_text_field( $order_reference ) ) );
		if ( 1 === preg_match( '/(\d+)$/', $order_reference, $matches ) ) {
			$candidates[] = $matches[1];
		}

		foreach ( array_values( array_unique( $candidates ) ) as $candidate ) {
			$order_id = NumericIdentifier::positive( $candidate );
			if ( null === $order_id ) {
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_number = sanitize_text_field( (string) $order->get_order_number() );
			if ( $order_number === $order_reference || $order_number === $candidate || (string) $order->get_id() === $candidate ) {
				return $order;
			}
		}

		return null;
	}

	private function find_order_by_wc_order_id( int $order_id ): ?WC_Order {
		if ( 0 >= $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	private function remote_order( string $soocool_order_id ): array|WP_Error {
		try {
			$response = $this->client->get_order( $soocool_order_id );
		} catch ( ApiException $exception ) {
			if ( 404 === $exception->status_code() ) {
				return array();
			}

			$status = $exception->status_code();
			$status = $status >= 400 && $status <= 599 ? $status : 502;
			return new WP_Error(
				'soocool_webhook_remote_lookup_failed',
				__( 'SooCool-order kon tijdelijk niet worden opgezocht. Probeer de webhook opnieuw.', 'soocool-for-woocommerce' ),
				array( 'status' => $status )
			);
		}

		$body = $response->body();
		if ( ! is_array( $body ) || array() === $body ) {
			return new WP_Error(
				'soocool_webhook_invalid_remote_order',
				__( 'SooCool gaf een ongeldige orderresponse terug. Probeer de webhook opnieuw.', 'soocool-for-woocommerce' ),
				array( 'status' => 502 )
			);
		}

		$candidates = array();
		$this->collect_remote_order_candidates( $body, $candidates );
		$matching = array();
		foreach ( $candidates as $candidate ) {
			$candidate_id = $this->payloads->soocool_order_id( $candidate );
			$candidate_reference = $this->payloads->order_reference( $candidate );
			if ( '' !== $candidate_id && hash_equals( $soocool_order_id, $candidate_id ) && '' !== $candidate_reference ) {
				$matching[ $candidate_id . '|' . $candidate_reference ] = $candidate;
			}
		}

		if ( 1 === count( $matching ) ) {
			$order = reset( $matching );
			return is_array( $order ) ? $order : array();
		}
		if ( 1 < count( $matching ) ) {
			return new WP_Error(
				'soocool_webhook_ambiguous_remote_order',
				__( 'SooCool gaf meerdere passende orders terug. Probeer de webhook opnieuw nadat de remote data is gecontroleerd.', 'soocool-for-woocommerce' ),
				array( 'status' => 502 )
			);
		}

		return new WP_Error(
			'soocool_webhook_invalid_remote_order',
			__( 'SooCool-orderresponse bevat geen geldige orderreferentie. Probeer de webhook opnieuw.', 'soocool-for-woocommerce' ),
			array( 'status' => 502 )
		);
	}

	private function collect_remote_order_candidates( array $value, array &$candidates, int $depth = 0 ): void {
		if ( $depth > 5 || count( $candidates ) >= 100 ) {
			return;
		}

		if ( ! array_is_list( $value ) ) {
			$candidates[] = $value;
		}
		foreach ( $value as $nested ) {
			if ( is_array( $nested ) ) {
				$this->collect_remote_order_candidates( $nested, $candidates, $depth + 1 );
			}
		}
	}

	public function link_known_webhook_order( WC_Order $order, string $soocool_order_id, string $order_reference ): void {
		if ( '' === $soocool_order_id ) {
			return;
		}

		$current_soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' !== $current_soocool_order_id && $current_soocool_order_id !== $soocool_order_id ) {
			return;
		}

		$body = array( 'orderId' => $soocool_order_id );
		if ( '' !== $order_reference ) {
			$body['orderReference'] = $order_reference;
		}

		$this->link_remote_order( $order, $body, $order_reference );
	}

	public function link_remote_order( WC_Order $order, array $remote_order, string $order_reference ): void {
		try {
			$this->meta->save_success( $order, $remote_order, $order_reference );
		} catch ( \InvalidArgumentException ) {
			// Keep the webhook update working even when the remote lookup response omits a stable orderId.
		}
	}

}
