<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Admin\OrderActionConfirmScript;
use SooCool\WooCommerce\Admin\OrderMetaBox;
use SooCool\WooCommerce\Domain\OrderSyncCoordinator;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderActions {

	public const SYNC_HOOK       = 'soocool_sync_order';
	public const RESYNC_HOOK     = 'soocool_resync_order';
	public const SCHEDULER_GROUP = 'soocool';
	public const QUEUE_SCHEDULED = 'scheduled';
	public const QUEUE_DUPLICATE = 'duplicate';
	public const QUEUE_FAILED    = 'failed';

	public function __construct(
		private readonly OrderMeta $meta,
		private readonly OrderMetaBox $meta_box,
		private readonly OrderActionConfirmScript $confirm_script,
		private readonly OrderSyncCoordinator $coordinator
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

	public function send_order_by_id( int $order_id ): void {
		$order = wc_get_order( absint( $order_id ) );
		if ( $order instanceof WC_Order ) {
			$this->send_to_soocool( $order, false );
		}
	}

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
		$actions['soocool_send_to_soocool']      = __( 'SooCool: order aanmaken/versturen', 'soocool-for-woocommerce' );
		$actions['soocool_refresh_from_soocool'] = __( 'SooCool: status vernieuwen', 'soocool-for-woocommerce' );
		$actions['soocool_update_at_soocool']    = __( 'SooCool: bestaande order bijwerken', 'soocool-for-woocommerce' );
		$actions['soocool_cancel_at_soocool']    = __( 'SooCool: order annuleren', 'soocool-for-woocommerce' );
		return $actions;
	}

	public function send_to_soocool( WC_Order $order, bool $force = false ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->sync_order( $order, $force );
		if ( (bool) ( $result['success'] ?? false ) ) {
			$note = ! empty( $result['existing'] )
				? __( 'Bestaande SooCool-order gevonden op orderreferentie. WooCommerce-order gekoppeld zonder dubbele SooCool-order aan te maken.', 'soocool-for-woocommerce' )
				: __( 'Resultaat: order naar SooCool verstuurd. Volgende stap: download het label of wacht op de track & trace-webhook.', 'soocool-for-woocommerce' );
			$order->add_order_note( $note );
			return;
		}

		$order->add_order_note( sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-synchronisatie mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) ) );
	}

	public function update_at_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->update_order( $order );
		$order->add_order_note(
			(bool) ( $result['success'] ?? false )
				? __( 'Resultaat: bestaande SooCool-order bijgewerkt. Volgende stap: vernieuw de status of controleer het SooCool-dashboard als fulfilment al is gestart.', 'soocool-for-woocommerce' )
				: sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-update mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) )
		);
	}

	public function refresh_from_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->refresh_order( $order );
		$order->add_order_note(
			(bool) ( $result['success'] ?? false )
				? sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-status vernieuwd.', 'soocool-for-woocommerce' ) ) )
				: sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-statusupdate mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) )
		);
	}

	public function cancel_at_soocool( WC_Order $order ): void {
		if ( ! $this->authorize_manual_order_action( $order ) ) {
			return;
		}

		$result = $this->coordinator->cancel_order( $order );
		$order->add_order_note(
			(bool) ( $result['success'] ?? false )
				? __( 'Resultaat: SooCool-order geannuleerd. Volgende stap: controleer de fulfilmentstatus in SooCool voordat je terugbetaalt of de WooCommerce-orderstatus wijzigt.', 'soocool-for-woocommerce' )
				: sanitize_text_field( (string) ( $result['message'] ?? __( 'SooCool-annulering mislukt. Controleer de SooCool-logs voor details.', 'soocool-for-woocommerce' ) ) )
		);
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
}
