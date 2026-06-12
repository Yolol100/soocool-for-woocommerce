<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderActionConfirmScript {

	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( wc_get_page_screen_id( 'shop-order' ), 'shop_order' ), true ) ) {
			return;
		}
		?>
		<script>
		(function(){
			var select = document.querySelector('select[name="wc_order_action"]');
			if (!select) { return; }
			var button = select.closest('.inside') ? select.closest('.inside').querySelector('button') : null;
			if (!button) { return; }
			button.addEventListener('click', function(event){
				var value = select.value;
				var message = '';
				if ('soocool_cancel_at_soocool' === value) {
					message = <?php echo wp_json_encode( __( 'This will cancel the linked order at SooCool. Continue only if fulfilment should be stopped.', 'soocool-for-woocommerce' ) ); ?>;
				} else if ('soocool_update_at_soocool' === value) {
					message = <?php echo wp_json_encode( __( 'This will update the existing SooCool order with the current WooCommerce order data. Continue only if the fulfilment data should change.', 'soocool-for-woocommerce' ) ); ?>;
				}
				if (message && ! window.confirm(message)) {
					event.preventDefault();
					event.stopPropagation();
				}
			});
		})();
		</script>
		<?php
	}
}
