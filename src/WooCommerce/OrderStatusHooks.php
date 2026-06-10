<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\WooCommerce;

use SooCool\WooCommerce\Infrastructure\OptionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderStatusHooks {

	public function __construct( private readonly OptionRepository $options, private readonly OrderActions $actions, private readonly OrderMeta $meta ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_auto_submit' ), 10, 4 );
	}

	public function maybe_auto_submit( int $order_id, string $old_status, string $new_status ): void {
		unset( $old_status );
		$settings = $this->options->all();
		if ( ! (bool) $settings['auto_submit_enabled'] || $new_status !== (string) $settings['auto_submit_status'] ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! (bool) $settings['allow_resubmit'] && $this->meta->is_synced( $order ) ) {
			return;
		}

		$this->actions->send_to_soocool( $order );
	}
}
