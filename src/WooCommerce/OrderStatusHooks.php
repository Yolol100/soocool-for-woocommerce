<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use WC_Order;

defined( 'ABSPATH' ) || exit;

final class OrderStatusHooks {

	public function __construct( private readonly OptionRepository $options, private readonly OrderActions $actions, private readonly OrderMeta $meta ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_submit' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'maybe_auto_submit_created_order' ), 20 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'maybe_auto_submit_created_order' ), 20 );
	}

	public function maybe_auto_submit( int $order_id, string $old_status, string $new_status, $order = null ): void {
		unset( $old_status );
		$settings = $this->options->all();
		if ( ! (bool) $settings['auto_submit_enabled'] || $new_status !== (string) $settings['auto_submit_status'] ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->schedule_order(
			$order,
			$settings,
			__( 'SooCool-synchronisatie is ingepland op de achtergrond na de orderstatuswijziging.', 'soocool-for-woocommerce' ),
			true
		);
	}

	public function maybe_auto_submit_created_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$settings = $this->options->all();
		if ( ! (bool) $settings['auto_submit_enabled'] || $order->get_status() !== (string) $settings['auto_submit_status'] ) {
			return;
		}

		$this->schedule_order(
			$order,
			$settings,
			__( 'SooCool-synchronisatie is direct na het aanmaken van de order op de achtergrond ingepland.', 'soocool-for-woocommerce' ),
			false
		);
	}

	/** @param array<string, mixed> $settings */
	private function schedule_order( WC_Order $order, array $settings, string $scheduled_note, bool $note_duplicate ): void {
		if ( ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			return;
		}

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
}
