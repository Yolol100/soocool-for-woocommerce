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
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderActions {

	/** Action Scheduler / cron hook used to send a selected order while respecting normal duplicate rules. */
	public const SYNC_HOOK   = 'soocool_sync_order';

	/** Action Scheduler / cron hook used to force-resync a single failed order in the background. */
	public const RESYNC_HOOK = 'soocool_resync_order';

	public function __construct(
		private readonly ApiClient $client,
		private readonly OrderPayloadBuilder $builder,
		private readonly OrderMeta $meta,
		private readonly OptionRepository $options,
		private readonly RemoteStatusMapper $remote_status,
		private readonly OrderMetaBox $meta_box,
		private readonly OrderActionConfirmScript $confirm_script,
		private readonly OrderSyncService $sync
	) {}

	public function register(): void {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_soocool_send_to_soocool', array( $this, 'send_to_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_update_at_soocool', array( $this, 'update_at_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_refresh_from_soocool', array( $this, 'refresh_from_soocool' ) );
		add_action( 'woocommerce_order_action_soocool_cancel_at_soocool', array( $this, 'cancel_at_soocool' ) );
		add_action( 'add_meta_boxes', array( $this->meta_box, 'register' ) );
		add_action( 'admin_footer', array( $this->confirm_script, 'render' ) );
		add_action( self::SYNC_HOOK, array( $this, 'send_order_by_id' ) );
		add_action( self::RESYNC_HOOK, array( $this, 'resync_order_by_id' ) );
	}

	/**
	 * Background-safe send for a selected order id. Used by the bulk "Send to SooCool"
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

	/** @param array<string, string> $actions @return array<string, string> */
	public function add_order_action( array $actions ): array {
		$actions['soocool_send_to_soocool'] = __( 'SooCool: create/send order', 'soocool-for-woocommerce' );
		$actions['soocool_refresh_from_soocool'] = __( 'SooCool: refresh status', 'soocool-for-woocommerce' );
		$actions['soocool_update_at_soocool'] = __( 'SooCool: update existing order', 'soocool-for-woocommerce' );
		$actions['soocool_cancel_at_soocool'] = __( 'SooCool: cancel order', 'soocool-for-woocommerce' );
		return $actions;
	}

	public function send_to_soocool( WC_Order $order, bool $force = false ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! $force && ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			$order->add_order_note( __( 'SooCool sync skipped because this order is already synced.', 'soocool-for-woocommerce' ) );
			return;
		}

		$order_id = (int) $order->get_id();
		if ( ! $this->sync->acquire_lock( $order_id ) ) {
			$order->add_order_note( __( 'SooCool sync skipped because another sync is already running for this order.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->meta->save_pending( $order );
			$payload = $this->builder->build( $order );

			$existing_order = $this->sync->find_existing_order( (string) $payload['orderReference'] );
			if ( array() !== $existing_order ) {
				$this->meta->save_success( $order, $existing_order, (string) $payload['orderReference'] );
				$order->add_order_note( __( 'Existing SooCool order found by order reference. WooCommerce order linked without creating a duplicate SooCool order.', 'soocool-for-woocommerce' ) );
				return;
			}

			$response         = $this->client->create_order( $payload );
			$body             = is_array( $response->body() ) ? $response->body() : array();
			$soocool_order_id = $this->meta->extract_order_id( $body );
			if ( '' === $soocool_order_id ) {
				throw new ApiException( esc_html__( 'SooCool did not return a valid order ID.', 'soocool-for-woocommerce' ) );
			}
			$this->meta->save_success( $order, $body, (string) $payload['orderReference'] );
			$order->add_order_note( __( 'Result: order sent to SooCool. Next step: download the label or wait for the track & trace webhook.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool validation failed: %s', 'soocool-for-woocommerce' ), $message ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_api_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool sync failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
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
			$order->add_order_note( __( 'SooCool update skipped because this WooCommerce order is not linked to a SooCool order ID yet.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$payload = $this->builder->build( $order );
			$this->client->update_order( $soocool_order_id, $payload );
			$this->meta->save_updated( $order );
			$order->add_order_note( __( 'Result: existing SooCool order updated. Next step: refresh the status or check the SooCool dashboard if fulfilment already started.', 'soocool-for-woocommerce' ) );
		} catch ( PayloadValidationException $exception ) {
			$message = sanitize_text_field( $exception->getMessage() );
			$this->meta->save_error( $order, $message );
			/* translators: %s: Validation error message returned while building the SooCool order payload. */
			$order->add_order_note( sprintf( __( 'SooCool update validation failed: %s', 'soocool-for-woocommerce' ), $message ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_update_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool update failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
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
			$order->add_order_note( __( 'SooCool refresh skipped because this WooCommerce order is not linked to a SooCool order ID.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$response = $this->client->get_order( $soocool_order_id );
			$body     = is_array( $response->body() ) ? $response->body() : array();
			if ( array() === $body ) {
				throw new ApiException( esc_html__( 'SooCool did not return a valid order response.', 'soocool-for-woocommerce' ) );
			}

			if ( '' === $this->meta->extract_order_id( $body ) ) {
				$body['orderId'] = $soocool_order_id;
			}

			$this->meta->save_success( $order, $body, $this->meta->get_our_reference( $order ) );
			$remote_data = $this->remote_status->map( $body );
			$changed     = $this->remote_status->has_status_data( $remote_data ) ? $this->meta->save_webhook_update( $order, $remote_data, false ) : false;

			$order->add_order_note( $changed ? __( 'Result: SooCool status refreshed and local status/tracking data updated.', 'soocool-for-woocommerce' ) : __( 'Result: SooCool status refreshed. No status or tracking changes were returned.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_refresh_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool refresh failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
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
			$order->add_order_note( __( 'SooCool cancel skipped because this WooCommerce order is not linked to a SooCool order ID.', 'soocool-for-woocommerce' ) );
			return;
		}

		try {
			$this->client->cancel_order( $soocool_order_id );
			$this->meta->save_cancelled( $order );
			$order->add_order_note( __( 'Result: SooCool order cancelled. Next step: verify fulfilment status in SooCool before refunding or changing the WooCommerce order status.', 'soocool-for-woocommerce' ) );
		} catch ( ApiException $exception ) {
			$message = $this->public_cancel_error_message( $exception );
			$this->meta->save_error( $order, $message );
			$order->add_order_note( $message );
		} catch ( \Throwable $exception ) {
			$message = __( 'SooCool cancel failed unexpectedly. Check the SooCool logs and PHP error log for details.', 'soocool-for-woocommerce' );
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

		$order->add_order_note( __( 'SooCool action skipped because the current user cannot manage WooCommerce.', 'soocool-for-woocommerce' ) );
		return false;
	}





	private function public_api_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();

		if ( '' === $message ) {
			return __( 'SooCool sync failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool sync failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	private function public_update_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();
		if ( '' === $message ) {
			return __( 'SooCool update failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool update failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	private function public_refresh_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();
		if ( '' === $message ) {
			return __( 'SooCool refresh failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool refresh failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

	private function public_cancel_error_message( ApiException $exception ): string {
		$message = $exception->getMessage();
		if ( '' === $message ) {
			return __( 'SooCool cancel failed. Check the SooCool logs for details.', 'soocool-for-woocommerce' );
		}

		return sprintf(
			/* translators: %s: safe SooCool API error summary. */
			__( 'SooCool cancel failed: %s', 'soocool-for-woocommerce' ),
			sanitize_text_field( $message )
		);
	}

}
