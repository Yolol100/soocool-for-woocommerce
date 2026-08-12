<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Infrastructure\OptionDefaults;
use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderStatusHooks {

	private readonly OrderDeliveryEligibility $eligibility;

	public function __construct( OptionRepository $options, private readonly OrderActions $actions, private readonly OrderMeta $meta, ?OrderDeliveryEligibility $eligibility = null ) {
		// Keep the constructor dependency for backward compatibility. Auto-submit is now a fixed integration rule.
		unset( $options );
		$this->eligibility = $eligibility ?? new OrderDeliveryEligibility();
	}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_submit' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'maybe_auto_submit_created_order' ), 20 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'maybe_auto_submit_processed_order' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'maybe_auto_submit_created_order' ), 20 );
	}

	public function maybe_auto_submit( int $order_id, string $old_status, string $new_status, mixed $order = null ): void {
		unset( $old_status );
		if ( ! $this->matches_auto_submit_status( $new_status ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! $this->eligibility->requires_delivery( $order ) ) {
			return;
		}

		$this->schedule_order(
			$order,
			__( 'SooCool-synchronisatie is ingepland op de achtergrond na de orderstatuswijziging.', 'soocool-for-woocommerce' ),
			true
		);
	}

	public function maybe_auto_submit_processed_order( int $order_id, mixed $posted_data = null, mixed $order = null ): void {
		unset( $posted_data );
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		$this->maybe_auto_submit_created_order( $order );
	}

	public function maybe_auto_submit_created_order( mixed $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! $this->matches_auto_submit_status( $order->get_status() ) ) {
			return;
		}
		if ( ! $this->eligibility->requires_delivery( $order ) ) {
			return;
		}

		$this->schedule_order(
			$order,
			__( 'SooCool-synchronisatie is direct na het aanmaken van de order op de achtergrond ingepland.', 'soocool-for-woocommerce' ),
			false
		);
	}

	private function schedule_order( WC_Order $order, string $scheduled_note, bool $note_duplicate ): void {
		$result = $this->actions->schedule_send_to_soocool( (int) $order->get_id() );
		if ( OrderActions::QUEUE_SCHEDULED === $result ) {
			$order->add_order_note( $scheduled_note );
			return;
		}

		if ( OrderActions::QUEUE_DUPLICATE === $result ) {
			if ( $note_duplicate ) {
				$order->add_order_note( __( 'SooCool-synchronisatie is overgeslagen omdat deze order al op de achtergrond ingepland staat.', 'soocool-for-woocommerce' ) );
			}
			return;
		}

		$order->add_order_note( __( 'SooCool-synchronisatie kon niet op de achtergrond worden ingepland. Gebruik de knop “Synchroniseer nu met SooCool” of controleer WooCommerce Action Scheduler en WP-Cron.', 'soocool-for-woocommerce' ) );
	}

	private function matches_auto_submit_status( string $order_status ): bool {
		return in_array(
			sanitize_key( $order_status ),
			array( OptionDefaults::AUTO_SUBMIT_STATUS, 'on-hold', 'processing', 'completed' ),
			true
		);
	}

}
