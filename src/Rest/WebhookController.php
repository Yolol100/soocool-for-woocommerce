<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Rest;

use SooCool\WooCommerce\Infrastructure\OptionMutex;
use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Infrastructure\Logger;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class WebhookController extends AbstractRestController {

	private const ORDER_LOCK_PREFIX         = 'soocool_webhook_order_lock_';
	private const ORDER_LOCK_TTL_SECONDS    = 120;
	private const ORDER_LOCK_RETRIES        = 5;
	private const ORDER_LOCK_RETRY_DELAY_US = 50000;

	private readonly WebhookOrderResolver $orders;

	private readonly WebhookIdentifiers $identifiers;

	public function __construct(
		private readonly OrderMeta $meta,
		private readonly Logger $logger,
		private readonly WebhookAuthenticator $authenticator,
		private readonly WebhookPayloadExtractor $payloads,
		private readonly ApiClient $client
	) {
		$this->orders      = new WebhookOrderResolver( $this->meta, $this->logger, $this->payloads, $this->client );
		$this->identifiers = new WebhookIdentifiers();
	}

	public function register_routes(): void {
		foreach ( array( '/webhook', '/webhook/(?P<wc_order_id>\d+)' ) as $route ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive' ),
					'permission_callback' => array( $this, 'can_receive' ),
				)
			);
		}
	}

	public function can_receive( WP_REST_Request $request ): bool|WP_Error {
		return $this->authenticator->can_receive( $request );
	}

	public function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			return $this->process_request( $request );
		} finally {
			$this->authenticator->release_reservation( $request );
		}
	}

	private function process_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$raw_body = $request->get_body();
		if ( is_string( $raw_body ) && strlen( $raw_body ) > WebhookPayloadExtractor::MAX_PAYLOAD_BYTES ) {
			return new WP_Error( 'soocool_webhook_payload_too_large', __( 'SooCool webhook-payload is te groot.', 'soocool-for-woocommerce' ), array( 'status' => 413 ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) || ! $this->payloads->shape_is_safe( $payload ) ) {
			return new WP_Error( 'soocool_webhook_invalid_payload', __( 'Ongeldige SooCool webhook-payload.', 'soocool-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( ! $this->payloads->identifiers_are_consistent( $payload ) ) {
			return new WP_Error( 'soocool_webhook_identifier_conflict', __( 'SooCool webhook bevat conflicterende orderidentificaties.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( ! $this->payloads->event_ordering_is_consistent( $payload ) ) {
			return new WP_Error( 'soocool_webhook_event_conflict', __( 'SooCool webhook bevat conflicterende eventvolgorde.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( ! $this->identifiers->request_identifiers_are_consistent( $request ) ) {
			return $this->orders->identifier_conflict( '', '', 0 );
		}

		$soocool_order_id       = $this->payloads->soocool_order_id( $payload );
		$payload_order_reference = $this->payloads->order_reference( $payload );
		$request_order_reference = $this->identifiers->webhook_order_reference( $request );
		$payload_wc_order_id     = $this->payloads->wc_order_id( $payload );
		$request_wc_order_id     = $this->identifiers->webhook_wc_order_id( $request );

		if ( '' !== $payload_order_reference && '' !== $request_order_reference && ! hash_equals( $payload_order_reference, $request_order_reference ) ) {
			return $this->orders->identifier_conflict( $soocool_order_id, $request_order_reference, $request_wc_order_id );
		}
		if ( 0 < $payload_wc_order_id && 0 < $request_wc_order_id && $payload_wc_order_id !== $request_wc_order_id ) {
			return $this->orders->identifier_conflict( $soocool_order_id, $payload_order_reference, $request_wc_order_id );
		}

		$order_reference = '' !== $payload_order_reference ? $payload_order_reference : $request_order_reference;
		$wc_order_id     = 0 < $request_wc_order_id ? $request_wc_order_id : $payload_wc_order_id;
		if ( ! $this->authenticator->refresh_reservation( $request ) ) {
			return new WP_Error( 'soocool_webhook_reservation_lost', __( 'De webhookverwerkingslease is verlopen. Probeer opnieuw.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$resolved = $this->orders->find_order( $soocool_order_id, $order_reference, $wc_order_id );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		if ( ! $this->authenticator->refresh_reservation( $request ) ) {
			return new WP_Error( 'soocool_webhook_reservation_lost', __( 'De webhookverwerkingslease is verlopen. Probeer opnieuw.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
		}

		if ( ! is_array( $resolved ) || ! ( $resolved['order'] ?? null ) instanceof WC_Order ) {
			$this->logger->info(
				'SooCool webhook uitgesteld: WooCommerce-order nog niet gevonden.',
				array(
					'status'         => 503,
					'path'           => sanitize_text_field( (string) $request->get_route() ),
					'orderId'        => '' !== $soocool_order_id ? $soocool_order_id : '[missing]',
					'orderReference' => '' !== $order_reference ? $order_reference : '[missing]',
					'wcOrderId'      => 0 < $wc_order_id ? (string) $wc_order_id : '[missing]',
				)
			);

			return new WP_Error(
				'soocool_webhook_order_not_ready',
				__( 'De WooCommerce-order is nog niet beschikbaar. Probeer de webhook later opnieuw.', 'soocool-for-woocommerce' ),
				array( 'status' => 503 )
			);
		}

		$order      = $resolved['order'];
		$order_id   = (int) $order->get_id();
		$lock_key   = self::ORDER_LOCK_PREFIX . md5( (string) $order_id );
		$lock_value = $this->acquire_order_lock( $lock_key );
		if ( null === $lock_value ) {
			return new WP_Error(
				'soocool_webhook_order_locked',
				__( 'Een andere SooCool webhook voor deze order wordt nog verwerkt. Probeer het opnieuw.', 'soocool-for-woocommerce' ),
				array( 'status' => 503 )
			);
		}

		try {
			if ( ! $this->refresh_order_lock( $lock_key, $lock_value ) ) {
				return new WP_Error( 'soocool_webhook_order_lock_lost', __( 'De orderverwerkingslease is verlopen. Probeer opnieuw.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
			}
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'soocool_webhook_order_not_ready', __( 'De WooCommerce-order is niet meer beschikbaar. Probeer de webhook later opnieuw.', 'soocool-for-woocommerce' ), array( 'status' => 503 ) );
			}

			$resolved_reference = $this->scalar_reference( $resolved['reference'] ?? '' );
			$remote_order       = is_array( $resolved['remote_order'] ?? null ) ? $resolved['remote_order'] : array();
			if ( method_exists( $order, 'read_meta_data' ) ) {
				$order->read_meta_data( true );
			}

			$delivery_timestamp = $this->authenticator->delivery_timestamp( $request );
			$event_timestamp    = $this->payloads->event_timestamp( $payload );
			if ( 0 < $event_timestamp && 0 < $delivery_timestamp && $event_timestamp > $delivery_timestamp + 300 ) {
				return new WP_Error( 'soocool_webhook_event_timestamp_invalid', __( 'SooCool webhook-eventtijd ligt onrealistisch in de toekomst.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
			}

			if ( ! $this->authenticator->refresh_reservation( $request ) || ! $this->refresh_order_lock( $lock_key, $lock_value ) ) {
				return new WP_Error( 'soocool_webhook_lease_lost', __( 'De webhookverwerkingslease is verlopen. Probeer opnieuw.', 'soocool-for-woocommerce' ), array( 'status' => 409 ) );
			}

			$data   = $this->payloads->update_data( $payload );
			$result = $this->meta->apply_webhook_update(
				$order,
				$data,
				true,
				array(
					'sequence'  => $this->payloads->event_sequence( $payload ),
					'timestamp' => $event_timestamp,
					'event_id'  => $this->authenticator->event_id( $request ),
				)
			);
			if ( $result['accepted'] || 'duplicate_event' === $result['reason'] ) {
				if ( array() !== $remote_order ) {
					$this->orders->link_remote_order( $order, $remote_order, $resolved_reference );
				} else {
					$this->orders->link_known_webhook_order( $order, $soocool_order_id, $resolved_reference );
				}
			}
			if ( $result['changed'] ) {
				$order->add_order_note( $this->webhook_note( $data ) );
			}
			$this->authenticator->mark_processed( $request );

			return new WP_REST_Response(
				array(
					'success'  => true,
					'order_id' => $order->get_id(),
					'changed'  => $result['changed'],
					'ignored'  => ! $result['accepted'],
					'reason'   => $result['reason'],
				),
				200
			);
		} finally {
			OptionMutex::release( $lock_key, $lock_value );
		}
	}

	private function acquire_order_lock( string $key ): ?string {
		for ( $attempt = 0; $attempt < self::ORDER_LOCK_RETRIES; ++$attempt ) {
			$value = OptionMutex::acquire( $key, self::ORDER_LOCK_TTL_SECONDS );
			if ( null !== $value ) {
				return $value;
			}
			if ( $attempt + 1 < self::ORDER_LOCK_RETRIES ) {
				usleep( self::ORDER_LOCK_RETRY_DELAY_US );
			}
		}

		return null;
	}

	private function refresh_order_lock( string $key, string &$value ): bool {
		$refreshed = OptionMutex::refresh( $key, $value, self::ORDER_LOCK_TTL_SECONDS );
		if ( null === $refreshed ) {
			return false;
		}

		$value = $refreshed;
		return true;
	}

	private function scalar_reference( mixed $value ): string {
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/** @param array<string, string> $data */
	private function webhook_note( array $data ): string {
		$parts = array();
		if ( '' !== ( $data['status'] ?? '' ) ) {
			$parts[] = sprintf(
				/* translators: %s: SooCool status. */
				__( 'status %s', 'soocool-for-woocommerce' ),
				sanitize_text_field( $data['status'] )
			);
		}
		if ( '' !== ( $data['tracking_code'] ?? '' ) ) {
			$parts[] = sprintf(
				/* translators: %s: tracking code. */
				__( 'tracking %s', 'soocool-for-woocommerce' ),
				sanitize_text_field( $data['tracking_code'] )
			);
		}

		return '' === implode( ', ', $parts )
			? __( 'SooCool webhook ontvangen.', 'soocool-for-woocommerce' )
			: sprintf(
				/* translators: %s: safe webhook summary. */
				__( 'SooCool webhook ontvangen: %s.', 'soocool-for-woocommerce' ),
				implode( ', ', $parts )
			);
	}
}
