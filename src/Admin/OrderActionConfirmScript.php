<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\AssetResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderActionConfirmScript {

	public function enqueue( string $hook ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( wc_get_page_screen_id( 'shop-order' ), 'shop_order' ), true ) ) {
			return;
		}

		$script_file = AssetResolver::filename( 'assets/admin', 'order-actions', 'js' );
		if ( '' === $script_file ) {
			return;
		}

		wp_enqueue_script(
			'soocool-order-actions',
			AssetResolver::url( 'assets/admin', $script_file ),
			array(),
			AssetResolver::version( 'assets/admin', $script_file ),
			true
		);

		wp_add_inline_script(
			'soocool-order-actions',
			'window.sooCoolOrderActions=' . wp_json_encode(
				array(
					'messages'     => array(
						'soocool_send_to_soocool'    => __( 'Dit verstuurt deze WooCommerce-order naar SooCool en kan daar een nieuwe order aanmaken. Doorgaan?', 'soocool-for-woocommerce' ),
						'soocool_update_at_soocool'  => __( 'Dit werkt de bestaande SooCool-order bij met de huidige WooCommerce-ordergegevens. Ga alleen door als de fulfilmentgegevens moeten wijzigen.', 'soocool-for-woocommerce' ),
						'soocool_refresh_from_soocool' => __( 'Dit haalt de status opnieuw op bij SooCool en kan lokale status- of trackinggegevens bijwerken. Doorgaan?', 'soocool-for-woocommerce' ),
						'soocool_cancel_at_soocool'  => __( 'Dit annuleert de gekoppelde order bij SooCool. Ga alleen door als de fulfilment moet worden gestopt.', 'soocool-for-woocommerce' ),
					),
					'bulkMessages' => array(
						'soocool_send_to_soocool'         => __( 'Dit verstuurt alle geselecteerde orders naar SooCool. Controleer de selectie voordat je doorgaat.', 'soocool-for-woocommerce' ),
						'soocool_download_order_labels' => __( 'Dit downloadt SooCool orderlabels voor alle geselecteerde orders. Doorgaan?', 'soocool-for-woocommerce' ),
						'soocool_download_good_labels'  => __( 'Dit downloadt SooCool goederenlabels voor alle geselecteerde orders. Doorgaan?', 'soocool-for-woocommerce' ),
					),
				)
			) . ';',
			'before'
		);
	}

}
