<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Infrastructure\OptionRepository;

defined( 'ABSPATH' ) || exit;

final class OrderStatusHooks {

	public function __construct( private readonly OptionRepository $options, private readonly OrderActions $actions, private readonly OrderMeta $meta ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_submit' ), 10, 4 );
	}

	public function maybe_auto_submit( int $order_id, string $old_status, string $new_status, $order = null ): void {
		unset( $old_status );
		$settings = $this->options->all();
		if ( ! (bool) $settings['auto_submit_enabled'] || $new_status !== (string) $settings['auto_submit_status'] ) {
			return;
		}

		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			return;
		}

		$result = $this->actions->schedule_send_to_soocool( $order_id );
		if ( OrderActions::QUEUE_SCHEDULED === $result ) {
			$order->add_order_note( __( 'SooCool-synchronisatie is ingepland op de achtergrond na de orderstatuswijziging.', 'soocool-for-woocommerce' ) );
			return;
		}

		if ( OrderActions::QUEUE_DUPLICATE === $result ) {
			$order->add_order_note( __( 'SooCool-synchronisatie is overgeslagen omdat deze order al op de achtergrond ingepland staat.', 'soocool-for-woocommerce' ) );
			return;
		}

		$order->add_order_note( __( 'SooCool-synchronisatie kon niet op de achtergrond worden ingepland. Controleer WooCommerce Action Scheduler of WP-Cron.', 'soocool-for-woocommerce' ) );
	}
}
