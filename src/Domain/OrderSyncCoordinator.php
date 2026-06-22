<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Domain;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\SecretSanitizer;
use SooCool\WooCommerce\WooCommerce\OrderMeta;
use SooCool\WooCommerce\WooCommerce\RemoteStatusMapper;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderSyncCoordinator {

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderMeta $meta,
		private readonly OptionRepository $options,
		private readonly OrderSyncService $sync,
		private readonly RemoteStatusMapper $remote_status,
		private readonly SecretSanitizer $sanitizer
	) {}

	/** @return array<string, mixed> */
	public function sync_order( WC_Order $order, bool $force = false ): array {
		$settings = $this->options->all();
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			return $this->result( false, __( 'Order is al met SooCool gesynchroniseerd.', 'soocool-for-woocommerce' ), 409 );
		}

		$order_id = (int) $order->get_id();
		if ( ! $this->sync->acquire_lock( $order_id ) ) {
			return $this->result( false, __( 'SooCool-synchronisatie draait al voor deze order. Probeer het zo opnieuw.', 'soocool-for-woocommerce' ), 409 );
		}

		try {
			$this->meta->save_pending( $order );
			$payload        = $this->builder->build( $order );
			$existing_order = $this->sync->find_existing_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$soocool_order_id = $this->meta->extract_order_id( $existing_order );
				$this->meta->save_success( $order, $existing_order, (string) $payload['orderReference'] );
				return $this->result(
					true,
					__( 'Bestaande SooCool-order gevonden op orderreferentie. Er is geen dubbele SooCool-order aangemaakt.', 'soocool-for-woocommerce' ),
					200,
					array(
						'orderId'      => $soocool_order_id,
						'ourReference' => isset( $existing_order['ourReference'] ) ? sanitize_text_field( (string) $existing_order['ourReference'] ) : sanitize_text_field( (string) $payload['orderReference'] ),
						'existing'     => true,
					)
				);
			}

			$response         = $this->client->create_order( $payload );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool gaf geen geldige order-ID terug.', 'soocool-for-woocommerce' ) );
			}

			$this->meta->save_success( $order, $body, (string) $payload['orderReference'] );
			return $this->result(
				true,
				__( 'Order naar SooCool verstuurd.', 'soocool-for-woocommerce' ),
				200,
				array(
					'orderId'      => $soocool_order_id,
					'ourReference' => isset( $body['ourReference'] ) ? sanitize_text_field( (string) $body['ourReference'] ) : sanitize_text_field( (string) $payload['orderReference'] ),
					'existing'     => false,
				)
			);
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, 422 );
		} catch ( ApiException $exception ) {
			/* translators: %s: Sanitized SooCool API error details. */
			$template = __( 'SooCool-synchronisatie mislukt: %s', 'soocool-for-woocommerce' );
			$message  = $this->public_api_error_message( $exception, __( 'SooCool-synchronisatie mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ), $template );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, $this->safe_status_code( $exception->status_code() ) );
		} catch ( \Throwable ) {
			$message = __( 'SooCool-synchronisatie is onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, 500 );
		} finally {
			$this->sync->release_lock( $order_id );
		}
	}

	/** @return array<string, mixed> */
	public function update_order( WC_Order $order ): array {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			return $this->result( false, __( 'SooCool-update overgeslagen omdat deze WooCommerce-order nog niet aan een SooCool order-ID is gekoppeld.', 'soocool-for-woocommerce' ), 409 );
		}

		try {
			$payload = $this->builder->build( $order );
			$this->client->update_order( $soocool_order_id, $payload );
			$this->meta->save_updated( $order );
			return $this->result( true, __( 'Bestaande SooCool-order bijgewerkt.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, 422 );
		} catch ( ApiException $exception ) {
			/* translators: %s: Sanitized SooCool API error details. */
			$template = __( 'SooCool-update mislukt: %s', 'soocool-for-woocommerce' );
			$message  = $this->public_api_error_message( $exception, __( 'SooCool-update mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ), $template );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, $this->safe_status_code( $exception->status_code() ) );
		} catch ( \Throwable ) {
			$message = __( 'SooCool-update onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, 500 );
		}
	}

	/** @return array<string, mixed> */
	public function refresh_order( WC_Order $order ): array {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			return $this->result( false, __( 'SooCool-statusupdate overgeslagen omdat deze WooCommerce-order niet aan een SooCool order-ID is gekoppeld.', 'soocool-for-woocommerce' ), 409 );
		}

		try {
			$response = $this->client->get_order( $soocool_order_id );
			$body     = is_array( $response->body() ) ? $response->body() : array();
			if ( array() === $body ) {
				throw new ApiException( esc_html__( 'SooCool gaf geen geldige orderresponse terug.', 'soocool-for-woocommerce' ) );
			}

			if ( '' === $this->meta->extract_order_id( $body ) ) {
				$body['orderId'] = $soocool_order_id;
			}

			$this->meta->save_success( $order, $body, $this->meta->get_our_reference( $order ) );
			$remote_data = $this->remote_status->map( $body );
			$changed     = $this->remote_status->has_status_data( $remote_data ) ? $this->meta->save_webhook_update( $order, $remote_data, false ) : false;

			return $this->result(
				true,
				$changed ? __( 'SooCool-status vernieuwd en lokale status-/trackingdata bijgewerkt.', 'soocool-for-woocommerce' ) : __( 'SooCool-status vernieuwd. Er zijn geen status- of trackingwijzigingen teruggegeven.', 'soocool-for-woocommerce' ),
				200,
				array( 'changed' => $changed )
			);
		} catch ( ApiException $exception ) {
			/* translators: %s: Sanitized SooCool API error details. */
			$template = __( 'SooCool-statusupdate mislukt: %s', 'soocool-for-woocommerce' );
			$message  = $this->public_api_error_message( $exception, __( 'SooCool-statusupdate mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ), $template );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, $this->safe_status_code( $exception->status_code() ) );
		} catch ( \Throwable ) {
			$message = __( 'SooCool-statusupdate onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, 500 );
		}
	}

	/** @return array<string, mixed> */
	public function cancel_order( WC_Order $order ): array {
		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			return $this->result( false, __( 'SooCool-annulering overgeslagen omdat deze WooCommerce-order niet aan een SooCool order-ID is gekoppeld.', 'soocool-for-woocommerce' ), 409 );
		}

		try {
			$this->client->cancel_order( $soocool_order_id );
			$this->meta->save_cancelled( $order );
			return $this->result( true, __( 'SooCool-order geannuleerd.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			/* translators: %s: Sanitized SooCool API error details. */
			$template = __( 'SooCool-annulering mislukt: %s', 'soocool-for-woocommerce' );
			$message  = $this->public_api_error_message( $exception, __( 'SooCool-annulering mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ), $template );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, $this->safe_status_code( $exception->status_code() ) );
		} catch ( \Throwable ) {
			$message = __( 'SooCool-annulering onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			return $this->result( false, $message, 500 );
		}
	}

	/** @param array<string, mixed> $data @return array<string, mixed> */
	private function result( bool $success, string $message, int $status = 200, array $data = array() ): array {
		return array_merge(
			array(
				'success' => $success,
				'message' => $message,
				'status'  => $status,
			),
			$data
		);
	}

	private function safe_status_code( int $status ): int {
		return $status >= 400 && $status <= 599 ? $status : 400;
	}

	private function public_api_error_message( ApiException $exception, string $fallback, string $template ): string {
		$details = $this->redacted_api_error_details( $exception );
		if ( '' === $details ) {
			return $fallback;
		}

		return sprintf( $template, $details );
	}

	private function redacted_api_error_details( ApiException $exception ): string {
		$message = $this->sanitizer->scrub_text( $exception->getMessage() );
		$errors  = array_map( array( $this->sanitizer, 'scrub_text' ), $exception->errors() );
		$errors  = array_values( array_unique( array_filter( $errors, static fn ( string $error ): bool => '' !== $error ) ) );

		if ( array() === $errors ) {
			return $message;
		}

		$error_summary = implode( '; ', $errors );
		return '' !== $message ? $message . ' (' . $error_summary . ')' : $error_summary;
	}
}
