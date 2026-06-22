<?php

declare(strict_types=1);

namespace SooCool\WooCommerce\Admin;

use SooCool\WooCommerce\Infrastructure\OptionRepository;
use SooCool\WooCommerce\Infrastructure\Requirements;

defined( 'ABSPATH' ) || exit;

final class Notices {

	public function __construct( private readonly Requirements $requirements, private readonly OptionRepository $options ) {}

	public function render_requirements_notice(): void {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->requirements->get_missing_message() ) );
	}

	public function render_runtime_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$this->render_checkout_blocks_notice();
		$this->render_webhook_signature_notice();
	}

	private function render_checkout_blocks_notice(): void {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return;
		}

		$checkout_page_id = wc_get_page_id( 'checkout' );
		if ( $checkout_page_id <= 0 ) {
			return;
		}

		if ( ! has_block( 'woocommerce/checkout', $checkout_page_id ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'SooCool ondersteunt in deze release alleen de klassieke WooCommerce checkout. De actieve checkoutpagina gebruikt WooCommerce Checkout Blocks; de bezorgmomentkiezer kan daardoor ontbreken.', 'soocool-for-woocommerce' )
		);
	}

	private function render_webhook_signature_notice(): void {
		if ( ! (bool) apply_filters( 'soocool_require_webhook_signature', false ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'SooCool webhook-HMAC is verplicht gemaakt via filter. Controleer op staging of SooCool de timestamp- en signature-headers voor deze koppeling meestuurt.', 'soocool-for-woocommerce' )
		);
	}
}
