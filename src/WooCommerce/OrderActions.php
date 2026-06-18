<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Api\ApiClient;
use SooCool\WooCommerce\Api\ApiException;
use SooCool\WooCommerce\Domain\OrderPayloadBuilder;
use SooCool\WooCommerce\Domain\OrderSyncService;
use SooCool\WooCommerce\Admin\OrderActionConfirmScript;
use SooCool\WooCommerce\Admin\OrderMetaBox;
use SooCool\WooCommerce\Domain\PayloadValidationException;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\SecretSanitizer;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderActions {

	/** Action Scheduler / cron hook used to send a selected order while respecting normal duplicate rules. */
	public const SYNC_HOOK   = 'soocool_sync_order';

	/** Action Scheduler / cron hook used to force-resync a single failed order in the background. */
	public const RESYNC_HOOK = 'soocool_resync_order';

	public const SCHEDULER_GROUP = 'soocool';
	public const QUEUE_SCHEDULED = 'scheduled';
	public const QUEUE_DUPLICATE = 'duplicate';
	public const QUEUE_FAILED = 'failed';

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderMeta $meta,
		private readonly OptionRepository $options,
		private readonly RemoteStatusMapper $remote_status,
		private readonly OrderMetaBox $meta_box,
		private readonly OrderActionConfirmScript $confirm_script,
		private readonly OrderSyncService $sync,
		private readonly SecretSanitizer $sanitizer
	) {}

	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_soocool_send_to_soocool', array( $this, 'send_to_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_update_at_soocool', array( $this, 'update_at_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_refresh_from_soocool', array( $this, 'refresh_from_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_cancel_at_soocool', array( $this, 'cancel_at_soocool' ) );
		add_action( 'add_meta_boxes', array( $this->meta_box, 'register' ) );
		add_action( 'admin_post_soocool_update_delivery_date', array( $this->meta_box, 'handle_update_delivery_date' ) );
		add_action( 'admin_notices', array( $this->meta_box, 'render_delivery_date_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this->confirm_script, 'enqueue' ) );
		add_action( self::SYNC_HOOK, array( $this, 'send_order_by_id' ) );
		add_action( self::RESYNC_HOOK, array( $this, 'resync_order_by_id' ) );
	}

	/**
	 * Background-safe send for a selected order id. Used by the bulk "Naar SooCool versturen"
	 * action and intentionally respects the normal resubmission setting.
	 */
	public function send_order_by_id( int $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			$this->send_to_soocool( $order, false );
		}
	}

	/**
	 * Background-safe force-resync for a single order id. Used by the
	 * "resync failed orders" maintenance action via Action Scheduler or cron.
	 */
	public function resync_order_by_id( int $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			$this->send_to_soocool( $order, true );
		}
	}

	public function schedule_send_to_soocool( int $order_id ): string {
		return $this->schedule_order_action( self::SYNC_HOOK, $order_id );
	}

	public function schedule_resync_order( int $order_id ): string {
		return $this->schedule_order_action( self::RESYNC_HOOK, $order_id );
	}

	private function schedule_order_action( string $hook, int $order_id ): string {
		$order_id = absint( $order_id );
		if ( 0 === $order_id ) {
			return self::QUEUE_FAILED;
		}

		$args = array( $order_id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::SCHEDULER_GROUP ) ) {
			return self::QUEUE_DUPLICATE;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action( $hook, $args, self::SCHEDULER_GROUP, true );
			if ( $action_id ) {
				return self::QUEUE_SCHEDULED;
			}

			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::SCHEDULER_GROUP ) ) {
				return self::QUEUE_DUPLICATE;
			}

			return self::QUEUE_FAILED;
		}

		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			return self::QUEUE_DUPLICATE;
		}

		return wp_schedule_single_event( time() + 10, $hook, $args ) ? self::QUEUE_SCHEDULED : self::QUEUE_FAILED;
	}

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_order_action( array $actions ): array {
		$actions['soocool_send_to_soocool'] = __( 'SooCool: order aanmaken/versturen', 'soocool-for-woocommerce' );
		$actions['soocool_refresh_from_soocool'] = __( 'SooCool: status vernieuwen', 'soocool-for-woocommerce' );
		$actions['soocool_update_at_soocool'] = __( 'SooCool: bestaande order bijwerken', 'soocool-for-woocommerce' );
		$actions['soocool_cancel_at_soocool'] = __( 'SooCool: order annuleren', 'soocool-for-woocommerce' );
		return $actions;
	}

	public function send_to_soocool( WC_Order $order, bool $force = false ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			$order->add_order_note( __( 'SooCool-synchronisatie overgeslagen omdat deze order al gesynchroniseerd is.', 'soocool-for-woocommerce' ) );
			return;
		}

		$order_id = (int) $order->get_id();
		if ( ! $this->sync->acquire_lock( $order_id ) ) {
			$order->add_order_note( __( 'SooCool-synchronisatie overgeslagen omdat er al een synchronisatie voor deze order draait.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->meta->save_pending( $order );
			$payload = $this->builder->build( $order );

			$existing_order = $this->sync->find_existing_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$this->meta->save_success( $order, $existing_order, (string) $payload['orderReference'] );
				$order->add_order_note( __( 'Bestaande SooCool-order gevonden op orderreferentie. WooCommerce-order gekoppeld zonder dubbele SooCool-order aan te maken.', 'soocool-for-woocommerce' ) );
				return;
			}

			$response         = $this->client->create_order( $payload );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool gaf geen geldige order-ID terug.', 'soocool-for-woocommerce' ) );
			}
			$this->meta->save_success( $order, $body, (string) $payload['orderReference'] );
			$order->add_order_note( __( 'Resultaat: order naar SooCool verstuurd. Volgende stap: download het label of wacht op de track & trace-webhook.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool-validatie mislukt: %s', 'soocool-for-woocommerce' ), $message ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_api_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool-synchronisatie onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} finally {
			$this->sync->release_lock( $order_id );
		}
	}

	public function update_at_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			$order->add_order_note( __( 'SooCool-update overgeslagen omdat deze WooCommerce-order nog niet aan een SooCool order-ID is gekoppeld.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$payload = $this->builder->build( $order );
			$this->client->update_order( $soocool_order_id, $payload );
			$this->meta->save_updated( $order );
			$order->add_order_note( __( 'Resultaat: bestaande SooCool-order bijgewerkt. Volgende stap: vernieuw de status of controleer het SooCool-dashboard als fulfilment al is gestart.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool-updatevalidatie mislukt: %s', 'soocool-for-woocommerce' ), $message ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_update_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool-update onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}

	public function refresh_from_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			$order->add_order_note( __( 'SooCool-statusupdate overgeslagen omdat deze WooCommerce-order niet aan een SooCool order-ID is gekoppeld.', 'soocool-for-woocommerce' ) );
			return;
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

			$order->add_order_note( $changed ? __( 'Resultaat: SooCool-status vernieuwd en lokale status-/trackingdata bijgewerkt.', 'soocool-for-woocommerce' ) : __( 'Resultaat: SooCool-status vernieuwd. Er zijn geen status- of trackingwijzigingen teruggegeven.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_refresh_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool-statusupdate onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}

	public function cancel_at_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$soocool_order_id = $this->meta->get_soocool_order_id( $order );
		if ( '' === $soocool_order_id ) {
			$order->add_order_note( __( 'SooCool-annulering overgeslagen omdat deze WooCommerce-order niet aan een SooCool order-ID is gekoppeld.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->client->cancel_order( $soocool_order_id );
			$this->meta->save_cancelled( $order );
			$order->add_order_note( __( 'Resultaat: SooCool-order geannuleerd. Volgende stap: controleer de fulfilmentstatus in SooCool voordat je terugbetaalt of de WooCommerce-orderstatus wijzigt.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_cancel_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool-annulering onverwacht mislukt. Controleer de SooCool-logs en PHP-foutlog voor details.', 'soocool-for-woocommerce' );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		}
	}

	private function authorize_manual_order_action( WC_Order $order ): bool {
		$current_filter = function_exists( 'current_filter' ) ? (string) current_filter() : '';
		if ( ! str_starts_with( $current_filter, 'woocommerce_order_action_soocool_' ) ) {
			return true;
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$order->add_order_note( __( 'SooCool-actie overgeslagen omdat de huidige gebruiker WooCommerce niet mag beheren.', 'soocool-for-woocommerce' ) );
		return false;
	}



	private function public_api_error_message( ApiException $exception ): string {
		return $this->format_api_error_message(
			$exception,
			__( 'SooCool-synchronisatie mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ),
			/* translators: %s: redacted SooCool API error summary. */
			__( 'SooCool-synchronisatie mislukt: %s', 'soocool-for-woocommerce' )
		);
	}

	private function public_update_error_message( ApiException $exception ): string {
		return $this->format_api_error_message(
			$exception,
			__( 'SooCool-update mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ),
			/* translators: %s: redacted SooCool API error summary. */
			__( 'SooCool-update mislukt: %s', 'soocool-for-woocommerce' )
		);
	}

	private function public_refresh_error_message( ApiException $exception ): string {
		return $this->format_api_error_message(
			$exception,
			__( 'SooCool-statusupdate mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ),
			/* translators: %s: redacted SooCool API error summary. */
			__( 'SooCool-statusupdate mislukt: %s', 'soocool-for-woocommerce' )
		);
	}

	private function public_cancel_error_message( ApiException $exception ): string {
		return $this->format_api_error_message(
			$exception,
			__( 'SooCool-annulering mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ),
			/* translators: %s: redacted SooCool API error summary. */
			__( 'SooCool-annulering mislukt: %s', 'soocool-for-woocommerce' )
		);
	}

	private function format_api_error_message( ApiException $exception, string $fallback, string $template ): string {
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
